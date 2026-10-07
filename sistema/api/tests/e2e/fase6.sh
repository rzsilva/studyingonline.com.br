#!/usr/bin/env bash
# Fase 6: dados da escola e cobrança da Adaline às instituições. Rodar DEPOIS de fase2..fase5,
# com schema_adaline.sql carregado, o simulador ligado e no .env: ADALINE_OPERADORES=admin@a.com,
# ADALINE_BOLETOCLOUD_CONTA_TOKEN=TOKEN-BC-ADALINE, ADALINE_TARIFA_BOLETO=2.50
#   MYSQL="mysql ... so_dev" PHP=php bash tests/e2e/fase6.sh
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


A=$(login admin@a.com senha123); AL=$(login aluno2@a.com aluno123); PR=$(login prof@a.com prof1234)
[ -n "$MYSQL" ] && sql "update USUARIO set MASTER=1 where EMAIL='prof@a.com'"
B=$(login prof@a.com prof1234)

echo "== dados da escola"
I=$(req $A GET /instituicao)
ok "admin lê a própria instituição" "$(echo "$I" | jget data.fantasia)" "'Escola A'"
ok "admin é operador da Adaline" "$(echo "$I" | jget data.operadorAdaline)" true
ok "professor não lê" "$(code $PR GET /instituicao)" 403
ok "aluno não altera" "$(code $AL PUT /instituicao '{"fantasia":"x"}')" 403
ok "cor inválida" "$(code $A PUT /instituicao '{"corPrimaria":"red;x"}')" 422
ok "logo sem https" "$(code $A PUT /instituicao '{"logo":"http://x/l.png"}')" 422
ok "fantasia obrigatória" "$(code $A PUT /instituicao '{"fantasia":""}')" 422
ok "salva cadastro" "$(code $A PUT /instituicao '{"razaoSocial":"Escola A Ltda","cnpj":"12.345.678/0001-90","emailCobranca":"fin@a.com","uf":"sp","cidade":"Campinas","cep":"13000-000","corPrimaria":"#0055aa"}')" 204
ok "UF em maiúsculas" "$(req $A GET /instituicao | jget data.uf)" "'SP'"
ok "admin não altera o contrato pelo cadastro" "$(code $A PUT /instituicao '{"plano":99}')" 204
ok "plano continua o mesmo" "$(req $A GET /instituicao | jget data.plano)" 1

echo "== tarifa de boleto entra no extrato"
req $A PUT /contas-bancarias/1 '{"autorizar":true}' >/dev/null
T=$(req $A POST /contas-receber "{\"usuarioId\":4,\"dataVencimento\":\"$(date +%F)\",\"valor\":\"80.00\"}" | jget data.id)
ok "aluno emite boleto" "$(code $AL POST /contas-receber/$T/pagar '{"forma":"boleto"}')" 200
EX=$(req $A GET /adaline/extrato)
ok "extrato registra o boleto" "$(echo "$EX" | jget data.0.valor)" 2.5
ok "extrato não cobrado ainda" "$(echo "$EX" | jget data.0.cobrado)" false
ok "aluno não vê extrato" "$(code $AL GET /adaline/extrato)" 403

echo "== painel da Adaline"
ok "MASTER fora da lista de operadores é barrado" "$(code $B GET /adaline/instituicoes)" 403
ok "professor é barrado" "$(code $PR GET /adaline/instituicoes)" 403
L=$(req $A GET /adaline/instituicoes)
ok "lista todas as instituições" "$(echo "$L" | jget data | $P -r 'echo count(json_decode(stream_get_contents(STDIN),true));')" 2
S=$(req $A GET /adaline/instituicoes/1/sugestao)
ok "sugestão soma boletos" "$(echo "$S" | jget data.boletosQtd)" 1
ok "excedentes = ativos - limite" "$($P -r '$d=json_decode($argv[1],true)["data"]; echo $d["excedentes"] === max(0,$d["ativos"]-1) ? "y":"n";' "$S")" y
ok "contrato com e-mail inválido" "$(code $A PUT /adaline/instituicoes/2 '{"emailCobranca":"x"}')" 422
ok "contrato de instituição inexistente" "$(code $A PUT /adaline/instituicoes/999 '{"plano":2}')" 404
ok "atualiza contrato" "$(code $A PUT /adaline/instituicoes/2 '{"plano":2,"alunosQtdMax":50}')" 204
ok "vencimento inválido na fatura" "$(code $A POST /adaline/faturas '{"instituicaoId":1,"vencimento":"2026-02-30","valorPlano":100}')" 422
ok "valor negativo" "$(code $A POST /adaline/faturas '{"instituicaoId":1,"vencimento":"2026-11-10","valorPlano":-1}')" 422
F=$(req $A POST /adaline/faturas '{"instituicaoId":1,"vencimento":"2026-11-10","valorPlano":"199,90","valorExcedente":10}' | jget data.id)
ok "fatura criada" "$([ -n "$F" ] && [ "$F" != "NULL" ] && echo y)" y
FT=$(req $A GET "/adaline/faturas?instituicaoId=1")
ok "total = plano + excedente + boletos" "$(echo "$FT" | jget data.0.valorTotal)" 212.4
ok "boleto do extrato fica vinculado" "$(req $A GET /adaline/extrato | jget data.0.faturaId)" "$F"
ok "escola B não vê fatura da A" "$(req $PR GET /adaline/faturas | grep -c "\"id\":$F,")" 0

echo "== boleto da fatura e baixa"
E=$(req $A POST /adaline/faturas/$F/boleto)
ok "emite na conta da Adaline" "$(echo "$E" | jget data.url)" "'https://app.boletocloud.com/boleto/2via/bc-5001'"
ok "segunda emissão reaproveita" "$(req $A POST /adaline/faturas/$F/boleto | jget data.url)" "'https://app.boletocloud.com/boleto/2via/bc-5001'"
[ -n "$MYSQL" ] && ok "número de documento sequencial (sem dobrar)" "$(sql "select BoletoNumDoc from AdalineConfiguracao")" 5001
VENC0=$([ -n "$MYSQL" ] && sql "select substr(DATA_VENCIMENTO,1,10) from INSTITUICAO where ID=1")
mock pagar '{"gateway":"boletocloud","ref":"bc-5001"}'
ok "sincronização dá baixa" "$(req $A POST /adaline/sincronizar | jget data.pagas)" 1
ok "fatura paga" "$(req $A GET "/adaline/faturas?instituicaoId=1" | jget data.0.situacao)" "'Pago'"
[ -n "$MYSQL" ] && ok "vencimento do contrato +1 mês" "$(sql "select substr(DATA_VENCIMENTO,1,10) from INSTITUICAO where ID=1")" "$(date -d "$VENC0 +1 month" +%F)"
ok "não duplica" "$(req $A POST /adaline/sincronizar | jget data.pagas)" 0
ok "fatura paga não é cancelada" "$(code $A POST /adaline/faturas/$F/cancelar)" 409

echo "== cancelamento devolve os boletos"
T2=$(req $A POST /contas-receber "{\"usuarioId\":4,\"dataVencimento\":\"$(date +%F)\",\"valor\":\"80.00\"}" | jget data.id)
req $AL POST /contas-receber/$T2/pagar '{"forma":"boleto"}' >/dev/null
F2=$(req $A POST /adaline/faturas '{"instituicaoId":1,"vencimento":"2026-12-10","valorPlano":100}' | jget data.id)
ok "cancela fatura em aberto" "$(code $A POST /adaline/faturas/$F2/cancelar)" 204
ok "boleto volta para a próxima cobrança" "$(req $A GET /adaline/instituicoes/1/sugestao | jget data.boletosQtd)" 1
ok "fatura inexistente" "$(code $A POST /adaline/faturas/9999/boleto)" 404
ok "rotina diária inclui faturas" "$(curl -s -X POST $U/rotinas/diaria -H 'X-Rotinas-Token: rotina-token-de-teste-123' | grep -c faturasAdalinePagas)" 1

echo; [ $FALHAS -eq 0 ] && echo "TODOS OS TESTES PASSARAM" || { echo "$FALHAS FALHA(S)"; exit 1; }
