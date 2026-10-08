#!/usr/bin/env bash
# Testes de ponta a ponta da Fase 5 (financeiro) contra o SIMULADOR de provedores:
#   php -S 127.0.0.1:8098 tests/mock/gateways.php
# Rodar DEPOIS de fase2..fase4, com schema_financeiro.sql carregado e o .env apontando para o simulador.
#   MYSQL="mysql ... so_dev" PHP=php bash tests/e2e/fase5.sh
U=${1:-localhost:8099/api}; MOCK=${MOCK:-http://127.0.0.1:8098}; J='Content-Type: application/json'; P=${PHP:-php}
FALHAS=0
jget() { $P -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){$d=$d[$k]??null;} echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }
login() { curl -s -X POST $U/auth/login -H "$J" -d "{\"email\":\"$1\",\"senha\":\"$2\"}" | jget data.accessToken | tr -d "'"; }
req() { if [ -n "$4" ]; then curl -s -X $2 "$U$3" -H "$J" -H "Authorization: Bearer $1" -d "$4"; else curl -s -X $2 "$U$3" -H "Authorization: Bearer $1"; fi; }
code() { if [ -n "$4" ]; then curl -s -o /dev/null -w '%{http_code}' -X $2 "$U$3" -H "$J" -H "Authorization: Bearer $1" -d "$4"; else curl -s -o /dev/null -w '%{http_code}' -X $2 "$U$3" -H "Authorization: Bearer $1"; fi; }
mock() { curl -s -X POST "$MOCK/_mock/$1" -H "$J" -d "$2" >/dev/null; }
sql() { $MYSQL -N -e "$1" 2>/dev/null; }
ok() { if [ "$2" == "$3" ]; then echo "  OK    $1"; else echo "  FALHA $1 (esperado $3, veio $2)"; FALHAS=$((FALHAS+1)); fi; }
mock reset '{}'

A=$(login admin@a.com senha123); AL=$(login aluno2@a.com aluno123); ANA=$(login ana@x.com Inscrito2026); PR=$(login prof@a.com prof1234)

echo "== contas de recebimento (credenciais)"
CB=$(req $A GET /contas-bancarias)
ok "lista as contas da instituição" "$(echo "$CB" | jget meta.total)" 2
ok "token do gateway nunca é devolvido" "$(echo "$CB" | grep -c 'TOKEN-BC\|TOKEN-VINDI\|gatewayToken')" 0
ok "indica se o token está configurado" "$(echo "$CB" | jget data.0.tokenConfigurado)" 1
ok "professor não vê contas" "$(code $PR GET /contas-bancarias)" 403
ok "gateway desconhecido" "$(code $A POST /contas-bancarias '{"banco":"Iugu"}')" 422
MPC=$(req $A POST /contas-bancarias '{"banco":"MercadoPago","titular":"Escola A","autorizar":true}' | jget data.id)
ok "grava credencial (sem devolver)" "$(code $A PUT /contas-bancarias/$MPC/token '{"token":"TESTE-MP"}')" 204
ok "credencial de outra instituição = 404" "$(code $A PUT /contas-bancarias/3/token '{"token":"hack"}')" 404

echo "== aluno paga a matrícula por boleto (BoletoCloud)"
MAT=$(req $ANA GET /me/financeiro | $P -r 'foreach(json_decode(stream_get_contents(STDIN),true)["data"] as $t) if(!$t["pago"] && !$t["cancelado"] && stripos((string)$t["descricao"],"MATR")!==false) {echo $t["id"]; break;}')
ok "formas disponíveis" "$(req $ANA GET /me/formas-pagamento | jget data)" '["boleto","cartao"]'
B1=$(req $ANA POST /contas-receber/$MAT/pagar '{"forma":"boleto"}')
ok "emite boleto e devolve a 2ª via" "$(echo "$B1" | jget data.url)" "'https://app.boletocloud.com/boleto/2via/bc-$MAT'"
ok "segundo clique reaproveita (idempotente)" "$(req $ANA POST /contas-receber/$MAT/pagar '{"forma":"boleto"}' | jget data.segundaVia)" true
ok "BoletoCloud chamado uma única vez" "$(curl -s $MOCK/_mock/estado -X POST | $P -r 'echo count(array_keys(json_decode(stream_get_contents(STDIN),true)["chamadas"], "/boletocloud/Criar"));')" 1
ok "aluno não paga título de outro aluno" "$(code $AL POST /contas-receber/$MAT/pagar '{"forma":"boleto"}')" 404
ok "forma inválida" "$(code $ANA POST /contas-receber/$MAT/pagar '{"forma":"pix"}')" 422
[ -n "$MYSQL" ] && sql "update USUARIO set INATIVO=1 where EMAIL='ana@x.com'"
mock pagar "{\"gateway\":\"boletocloud\",\"ref\":\"bc-$MAT\"}"
S=$(req $A POST /financeiro/sincronizar)
ok "sincronização dá baixa" "$(echo "$S" | jget data.pagos)" 1
ok "título pago" "$(req $A GET /contas-receber/$MAT | jget data.listaSituacaoCrId)" 2
[ -n "$MYSQL" ] && ok "matrícula paga reativa o aluno" "$(sql "select INATIVO from USUARIO where EMAIL='ana@x.com'")" 0
ok "sincronizar de novo não duplica" "$(req $A POST /financeiro/sincronizar | jget data.pagos)" 0
ok "título pago não é cobrado de novo" "$(code $ANA POST /contas-receber/$MAT/pagar '{"forma":"boleto"}')" 409

echo "== cartão (Vindi)"
T2=$(req $A POST /contas-receber "{\"usuarioId\":4,\"dataVencimento\":\"$(date -d '+20 days' +%F 2>/dev/null || date +%F)\",\"valor\":\"150,00\",\"listaCategoriaCrId\":1}" | jget data.id)
C1=$(req $AL POST /contas-receber/$T2/pagar '{"forma":"cartao"}')
ok "fatura Vindi criada" "$(echo "$C1" | jget data.gateway)" "'Vindi'"
VID=$(echo "$C1" | jget data.url | grep -oE 'bills/[0-9]+' | cut -d/ -f2)
mock pagar "{\"gateway\":\"vindi\",\"ref\":\"$VID\"}"
ok "baixa da Vindi na sincronização" "$(req $A POST /financeiro/sincronizar | jget data.pagos)" 1

echo "== MercadoPago (link + webhook)"
req $A PUT /contas-bancarias/1 '{"autorizar":false}' >/dev/null # desliga BoletoCloud: boleto passa a ir pelo MercadoPago
T3=$(req $A POST /contas-receber "{\"usuarioId\":4,\"dataVencimento\":\"$(date +%F)\",\"valor\":\"99.90\"}" | jget data.id)
M1=$(req $AL POST /contas-receber/$T3/pagar '{"forma":"boleto"}')
ok "link do Checkout Pro" "$(echo "$M1" | jget data.gateway)" "'MercadoPago'"
WH=$(req $A GET /contas-bancarias/$MPC/webhook | jget data.url | tr -d "'")
ok "URL de webhook por instituição" "$(echo "$WH" | grep -cE '/webhooks/mercadopago/1/[a-f0-9]{40}$')" 1
WHPATH=${WH#*://*/api}
ok "notificação com token errado = 403" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$U/webhooks/mercadopago/1/$(printf 'a%.0s' {1..40})" -H "$J" -d "{\"type\":\"payment\",\"data\":{\"id\":\"$T3\"}}")" 403
ok "token de uma instituição não vale para outra" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$U${WHPATH/mercadopago\/1\//mercadopago/2/}" -H "$J" -d "{\"type\":\"payment\",\"data\":{\"id\":\"$T3\"}}")" 403
ok "pagamento ainda pendente no provedor" "$(curl -s -X POST "$U$WHPATH" -H "$J" -d "{\"type\":\"payment\",\"data\":{\"id\":\"$T3\"}}" | jget data.resultado)" "'pendente'"
mock pagar "{\"gateway\":\"mercadopago\",\"ref\":\"$T3\"}"
ok "webhook consulta a API e dá baixa" "$(curl -s -X POST "$U$WHPATH" -H "$J" -d "{\"type\":\"payment\",\"data\":{\"id\":\"$T3\"}}" | jget data.resultado)" "'baixado'"
ok "notificação repetida é idempotente" "$(curl -s -X POST "$U$WHPATH" -H "$J" -d "{\"type\":\"payment\",\"data\":{\"id\":\"$T3\"}}" | jget data.resultado)" "'ja_processado'"
ok "tipo de evento irrelevante é ignorado" "$(curl -s -X POST "$U$WHPATH" -H "$J" -d '{"type":"plan","data":{"id":"1"}}' | jget data.resultado)" "'ignorado'"

echo "== provedor fora do ar"
T4=$(req $A POST /contas-receber "{\"usuarioId\":4,\"dataVencimento\":\"$(date +%F)\",\"valor\":\"10\"}" | jget data.id)
mock falhar '{"on":true}'
ok "falha do provedor vira 502 amigável" "$(code $AL POST /contas-receber/$T4/pagar '{"forma":"boleto"}')" 502
ok "sincronização registra erro sem quebrar" "$(code $A POST /financeiro/sincronizar)" 200
mock falhar '{"on":false}'

echo "== baixa manual, cancelamento"
ok "aluno não dá baixa" "$(code $AL POST /contas-receber/$T4/baixa '{}')" 403
ok "data futura recusada" "$(code $A POST /contas-receber/$T4/baixa '{"dataPagamento":"2099-01-01"}')" 422
ok "baixa manual" "$(code $A POST /contas-receber/$T4/baixa '{"observacao":"Pago em dinheiro"}')" 204
ok "título pago não é cancelado" "$(code $A POST /contas-receber/$T4/cancelar '{"motivo":"x"}')" 409
T5=$(req $A POST /contas-receber "{\"usuarioId\":4,\"dataVencimento\":\"$(date +%F)\",\"valor\":\"10\"}" | jget data.id)
ok "cancelar exige motivo" "$(code $A POST /contas-receber/$T5/cancelar '{}')" 422
ok "cancelar" "$(code $A POST /contas-receber/$T5/cancelar '{"motivo":"Lan\u00e7ado em duplicidade"}')" 204
ok "valor zero recusado" "$(code $A POST /contas-receber '{"usuarioId":4,"dataVencimento":"2026-12-01","valor":"0"}')" 422
ok "aluno de outra instituição recusado" "$(code $A POST /contas-receber '{"usuarioId":3,"dataVencimento":"2026-12-01","valor":"10"}')" 422

echo "== geração em lote"
COMP=$(date -d '+2 months' +%Y-%m 2>/dev/null || date +%Y-%m)
SIM=$(req $A POST /financeiro/mensalidades "{\"competencia\":\"$COMP\",\"simular\":true}")
QTD=$(echo "$SIM" | $P -r 'echo count(json_decode(stream_get_contents(STDIN),true)["data"]["gerados"]);')
ok "simulação mostra o que seria gerado" "$([ "$QTD" -gt 0 ] && echo y)" y
ok "simulação não grava" "$(req $A POST /financeiro/mensalidades "{\"competencia\":\"$COMP\",\"simular\":true}" | $P -r 'echo count(json_decode(stream_get_contents(STDIN),true)["data"]["gerados"]);')" "$QTD"
ok "gera as mensalidades" "$(req $A POST /financeiro/mensalidades "{\"competencia\":\"$COMP\"}" | $P -r 'echo count(json_decode(stream_get_contents(STDIN),true)["data"]["gerados"]);')" "$QTD"
ok "não duplica na mesma competência" "$(req $A POST /financeiro/mensalidades "{\"competencia\":\"$COMP\"}" | $P -r 'echo count(json_decode(stream_get_contents(STDIN),true)["data"]["gerados"]);')" 0
ok "competência inválida" "$(code $A POST /financeiro/mensalidades '{"competencia":"2026-13"}')" 422
if [ -n "$MYSQL" ]; then
  # recuperação de mês que ficou sem gerar (legado: IEBIR ago/set 2026), mesmo com meses posteriores já lançados
  PASS=$(date -d '-1 month' +%Y-%m)
  sql "update USUARIO_CURSO set DATA_CADASTRO='2020-01-01' where INSTITUICAO_ID=1"
  RETRO=$(req $A POST /financeiro/mensalidades "{\"competencia\":\"$PASS\"}" | $P -r 'echo count(json_decode(stream_get_contents(STDIN),true)["data"]["gerados"]);')
  ok "retroativo preenche o mês que faltou" "$([ "$RETRO" -gt 0 ] && echo y)" y
  ok "retroativo não duplica" "$(req $A POST /financeiro/mensalidades "{\"competencia\":\"$PASS\"}" | $P -r 'echo count(json_decode(stream_get_contents(STDIN),true)["data"]["gerados"]);')" 0
  sql "update USUARIO_CURSO set DATA_CADASTRO=NOW() where INSTITUICAO_ID=1"
  ok "retroativo não cobra antes da inscrição" "$(req $A POST /financeiro/mensalidades "{\"competencia\":\"$(date -d '-3 months' +%Y-%m)\"}" | $P -r 'echo count(json_decode(stream_get_contents(STDIN),true)["data"]["gerados"]);')" 0
fi
ROT=$(curl -s -X POST $U/rotinas/diaria -H 'X-Rotinas-Token: rotina-token-de-teste-123')
ok "rotina diária gera as mensalidades do mês" "$(echo "$ROT" | grep -c '"mensalidades"')" 1
ok "rotina diária é idempotente" "$(curl -s -X POST $U/rotinas/diaria -H 'X-Rotinas-Token: rotina-token-de-teste-123' | jget data.mensalidades)" 0
ok "contas fixas viram contas a pagar" "$(req $A POST /financeiro/contas-fixas/gerar '{"competencia":"2026-02"}' | jget data.geradas)" 2
[ -n "$MYSQL" ] && ok "dia 31 em fevereiro vira o último dia" "$(sql "select substr(DATA_VENCIMENTO,1,10) from CONTAS_PAGAR where DESCRICAO='Folha de pagamento'")" "2026-02-28"
ok "contas fixas não duplicam" "$(req $A POST /financeiro/contas-fixas/gerar '{"competencia":"2026-02"}' | jget data.geradas)" 0

echo "== relatórios"
R=$(req $A GET "/financeiro/resumo?de=2026-01-01&ate=2026-12-31")
ok "resumo tem recebido" "$($P -r 'echo json_decode($argv[1],true)["data"]["receber"]["recebido"] > 0 ? "y" : "n";' "$R")" y
ok "professor não vê resumo" "$(code $PR GET /financeiro/resumo)" 403
CSV=$(curl -s "$U/financeiro/receber/exportar?de=2026-01-01&ate=2026-12-31" -H "Authorization: Bearer $A")
ok "CSV com cabeçalho" "$(echo "$CSV" | head -1 | grep -c 'Vencimento;Pagamento;Valor')" 1
ok "CSV só da instituição" "$(echo "$CSV" | grep -c 'Escola B')" 0
CSV2=$(curl -s "$U/financeiro/receber/exportar?de=2026-01-01&ate=2026-12-31&tipo=recebidos" -H "Authorization: Bearer $A")
ok "planilha com colunas do relatório legado" "$(echo "$CSV2" | head -1 | grep -c 'E-mail;Celular;CPF;Ativo;Campus;Curso;Turma')" 1
ok "filtro recebidos só traz pagos" "$(echo "$CSV2" | tail -n +2 | grep ';' | grep -vc ';RECEBIDO;')" 0
ok "forma de pagamento identificada" "$(echo "$CSV2" | grep -q ';Boleto;' && echo y)" y
ok "tipo inválido" "$(code $A GET '/financeiro/receber/exportar?tipo=xpto')" 422
ok "data inválida" "$(code $A GET '/financeiro/receber/exportar?de=2026-02-30')" 422
[ -n "$MYSQL" ] && ok "eventos de pagamento registrados" "$(sql "select count(*) from PAGAMENTO_EVENTO where STATUS='pago'")" 4

echo; [ $FALHAS -eq 0 ] && echo "TODOS OS TESTES PASSARAM" || { echo "$FALHAS FALHA(S)"; exit 1; }
