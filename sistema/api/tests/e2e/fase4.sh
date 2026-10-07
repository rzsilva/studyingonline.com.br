#!/usr/bin/env bash
# Testes de ponta a ponta da Fase 4 (secretaria). Rodar DEPOIS de fase2.sh e fase3.sh,
# com schema_secretaria.sql carregado. Precisa do cliente mysql para conferir o banco:
#   MYSQL="mysql -uroot -P3307 -h127.0.0.1 so_dev" PHP=php bash tests/e2e/fase4.sh
U=${1:-localhost:8099/api}; J='Content-Type: application/json'; P=${PHP:-php}; HOST='X-Instituicao-Host: escolaa.studyingonline.com.br'
DIR=$(cd "$(dirname "$0")/../.." && pwd); MAILDIR="$DIR/storage/mail"
TMP=$(mktemp -d); FALHAS=0
jget() { $P -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){$d=$d[$k]??null;} echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }
login() { curl -s -X POST $U/auth/login -H "$J" -d "{\"email\":\"$1\",\"senha\":\"$2\"}" | jget data.accessToken | tr -d "'"; }
req() { if [ -n "$4" ]; then curl -s -X $2 "$U$3" -H "$J" -H "Authorization: Bearer $1" -d "$4"; else curl -s -X $2 "$U$3" -H "Authorization: Bearer $1"; fi; }
code() { if [ -n "$4" ]; then curl -s -o /dev/null -w '%{http_code}' -X $2 "$U$3" -H "$J" -H "Authorization: Bearer $1" -d "$4"; else curl -s -o /dev/null -w '%{http_code}' -X $2 "$U$3" -H "Authorization: Bearer $1"; fi; }
limpa() { rm -f "$DIR"/storage/ratelimit/*.json; }
pub() { limpa; curl -s -X POST "$U/publico/inscricoes" -H "$J" -H "$HOST" -d "$1"; }
pubcode() { limpa; curl -s -o /dev/null -w '%{http_code}' -X POST "$U/publico/inscricoes" -H "$J" -H "$HOST" -d "$1"; }
sql() { $MYSQL -N -e "$1" 2>/dev/null; }
ok() { if [ "$2" == "$3" ]; then echo "  OK    $1"; else echo "  FALHA $1 (esperado $3, veio $2)"; FALHAS=$((FALHAS+1)); fi; }
pessoa() { echo "{\"cursoId\":$1,\"nome\":\"$2\",\"email\":\"$3\",\"cpf\":\"$4\",\"celular\":\"(21) 99999-0000\",\"dataNascimento\":\"1990-05-10\",\"senha\":\"Inscrito2026\",\"deAcordo\":true,\"formaPagamento\":1$5}"; }
rm -f "$MAILDIR"/*.html

A=$(login admin@a.com senha123); AL=$(login aluno2@a.com aluno123); PR=$(login prof@a.com prof1234)

echo "== cursos abertos (público)"
CA=$(curl -s "$U/publico/cursos" -H "$HOST")
ok "lista curso aberto" "$(echo "$CA" | grep -c 'Curso Livre de Teologia')" 1
ok "período encerrado não aparece" "$(echo "$CA" | grep -c 'Curso Encerrado')" 0
ok "outra instituição não aparece" "$(echo "$CA" | grep -c 'Escola B')" 0
ok "vagas restantes" "$(echo "$CA" | $P -r '$d=json_decode(stream_get_contents(STDIN),true)["data"]; foreach($d as $c) if($c["id"]==200) echo $c["vagasRestantes"];')" 2
ok "matrícula zerada usa a mensalidade (bug do \"0.00\")" "$(echo "$CA" | $P -r '$d=json_decode(stream_get_contents(STDIN),true)["data"]; foreach($d as $c) if($c["id"]==201) echo $c["valorMatricula"];')" 150
ok "sem host = 404" "$(curl -s -o /dev/null -w '%{http_code}' "$U/publico/cursos")" 404

echo "== inscrição pública"
R=$(pub '{"cursoId":200,"nome":"Ana","email":"x","cpf":"123","celular":"1","senha":"abc"}')
ok "validação (nome/email/cpf/celular/senha)" "$(echo "$R" | jget error.fields | $P -r 'echo count(json_decode(stream_get_contents(STDIN),true));')" 5
ok "honeypot anti-robô" "$(pubcode "$(pessoa 200 'Robo da Silva' robo@x.com 39053344705 ',"website":"spam"')")" 400
ok "sem aceite dos termos" "$(pubcode '{"cursoId":200,"nome":"Ana Souza","email":"ana@x.com","cpf":"52998224725","celular":"21999990000","senha":"Inscrito2026"}')" 422
R=$(pub "$(pessoa 200 'Ana Souza' ana@x.com 529.982.247-25 ',"questionario":{"emergenciaNome":"Jo\u00e3o","igrejaMembro":true,"campoInvalido":"x"}')")
ANA_INSC=$(echo "$R" | jget data.inscricaoId); TOK=$(echo "$R" | jget data.tokenDocumentos | tr -d "'")
ok "inscrição criada" "$([ "$ANA_INSC" -gt 0 ] 2>/dev/null && echo y)" y
ok "matrícula no formato do legado" "$(echo "$R" | jget data.matricula | grep -cE "^'$(date +%Y)20[12][0-9]{4}'$")" 1
ok "e-mail repetido = 409" "$(pubcode "$(pessoa 200 'Outra Pessoa' ana@x.com 11144477735)")" 409
ok "CPF repetido = 409" "$(pubcode "$(pessoa 200 'Outra Pessoa' outra@x.com 52998224725)")" 409
ok "curso com pré-requisito exige login" "$(pubcode "$(pessoa 201 'Bia Lima' bia@x.com 11144477735)")" 422
ok "curso com período encerrado" "$(pubcode "$(pessoa 202 'Bia Lima' bia@x.com 11144477735)")" 422
ok "instituição não vem do corpo" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $U/publico/inscricoes -H "$J" -d "$(pessoa 200 'Bia Lima' bia@x.com 11144477735 ',"instituicaoId":2')")" 404
R2=$(pub "$(pessoa 200 'Bia Lima' bia@x.com 11144477735)"); BIA_INSC=$(echo "$R2" | jget data.inscricaoId)
ok "segunda vaga" "$([ "$BIA_INSC" -gt 0 ] 2>/dev/null && echo y)" y
ok "sem vagas (limite 2)" "$(pubcode "$(pessoa 200 'Caio Reis' caio@x.com 39053344705)")" 422
if [ -n "$MYSQL" ]; then
  ok "usuário novo inativo, perfil aluno, com hash" "$(sql "select concat(INATIVO,LISTA_PERFIL_ID,SENHA_HASH like '\$argon2id\$%' or SENHA_HASH like '\$2y\$%') from USUARIO where EMAIL='ana@x.com'")" 131
  ok "CPF gravado com máscara do legado" "$(sql "select CPF from USUARIO where EMAIL='ana@x.com'")" "529.982.247-25"
  ok "cobrança de matrícula (R\$80, cat. 2, a receber)" "$(sql "select concat(cast(VALOR as unsigned),'-',LISTA_CATEGORIA_CR_ID,'-',LISTA_SITUACAO_CR_ID) from CONTAS_RECEBER cr join USUARIO u on u.ID=cr.USUARIO_ID where u.EMAIL='ana@x.com'")" "80-2-1"
  ok "questionário só com campos conhecidos" "$(sql "select concat(EMERGENCIA_NOME,'/',IGREJA_MEMBRO) from INSCRICAO where ID=$ANA_INSC")" "João/1"
fi
ok "e-mail de confirmação enviado" "$(grep -l 'ana@x.com' "$MAILDIR"/*.html 2>/dev/null | xargs grep -l 'INSCRIÇÃO REALIZADA' | wc -l | tr -d ' ')" 1

echo "== documentos"
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n' > $TMP/rg.pdf; printf '<?php echo 1;' > $TMP/ruim.pdf
up() { curl -s -o /dev/null -w '%{http_code}' -X POST "$U/publico/inscricoes/documentos/$1" -H "Authorization: Documento $2" -F arquivo=@$3; }
ok "envio com token da inscrição" "$(up url_rg "$TOK" $TMP/rg.pdf)" 204
ok "token inválido" "$(up url_rg "${TOK}x" $TMP/rg.pdf)" 401
ok "PHP disfarçado recusado" "$(up url_cpf "$TOK" $TMP/ruim.pdf)" 422
ok "campo inexistente" "$(up senha "$TOK" $TMP/rg.pdf)" 422
ANA=$(login ana@x.com Inscrito2026)
ok "inscrito entra no sistema" "$([ -n "$ANA" ] && [ "$ANA" != NULL ] && echo y)" y
ok "vê a própria inscrição pendente" "$(req $ANA GET /me/inscricao | jget data.status)" 1
ok "envia documento logado" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $U/me/inscricao/documentos/url_cpf -H "Authorization: Bearer $ANA" -F arquivo=@$TMP/rg.pdf)" 204
ok "aluno baixa o próprio documento" "$(curl -s $U/inscricoes/$ANA_INSC/documentos/url_rg -H "Authorization: Bearer $ANA" | head -c 8)" "%PDF-1.4"
ok "outro aluno não baixa" "$(code $AL GET /inscricoes/$ANA_INSC/documentos/url_rg)" 404
ok "professor não baixa" "$(code $PR GET /inscricoes/$ANA_INSC/documentos/url_rg)" 404

echo "== análise pela secretaria"
ok "professor não lista inscrições" "$(code $PR GET /inscricoes)" 403
ok "aluno não lista inscrições" "$(code $ANA GET /inscricoes)" 403
ok "lista pendentes" "$(req $A GET '/inscricoes?status=1' | jget meta.total)" 2
DET=$(req $A GET /inscricoes/$ANA_INSC)
ok "detalhe mostra documentos enviados" "$(echo "$DET" | jget data.documentos.0.enviado)" true
ok "detalhe sem senha" "$(echo "$DET" | grep -ci senha)" 0
ok "admin baixa documento" "$(curl -s $U/inscricoes/$ANA_INSC/documentos/url_rg -H "Authorization: Bearer $A" | head -c 8)" "%PDF-1.4"
ok "reprovar exige motivo" "$(code $A PUT /inscricoes/$BIA_INSC/status '{"status":3}')" 422
ok "aluno não aprova" "$(code $ANA PUT /inscricoes/$ANA_INSC/status '{"status":2}')" 403
ok "reprovar" "$(code $A PUT /inscricoes/$BIA_INSC/status '{"status":3,"justificativa":"Documenta\u00e7\u00e3o incompleta"}')" 200
[ -n "$MYSQL" ] && ok "reprovação cancela a cobrança (situação 4)" "$(sql "select LISTA_SITUACAO_CR_ID from CONTAS_RECEBER cr join USUARIO u on u.ID=cr.USUARIO_ID where u.EMAIL='bia@x.com'")" 4
ok "decisão não se repete" "$(code $A PUT /inscricoes/$BIA_INSC/status '{"status":2}')" 409
ok "aprovar" "$(code $A PUT /inscricoes/$ANA_INSC/status '{"status":2}')" 200
ok "e-mails de aprovação/reprovação" "$(grep -lE 'INSCRIÇÃO (APROVADA|NÃO APROVADA)' "$MAILDIR"/*.html 2>/dev/null | wc -l | tr -d ' ')" 2
ok "motivo escapado no e-mail" "$(grep -l 'Documentação incompleta' "$MAILDIR"/*.html | wc -l | tr -d ' ')" 1

echo "== usuários e convite"
ok "CPF inválido" "$(req $A POST /usuarios '{"nome":"Prof Novo","email":"novo@a.com","cpf":"11111111111","listaPerfilId":2,"listaUnidadeId":1,"listaEstadoCivilId":1}' | jget error.fields.cpf)" "'CPF inválido.'"
ok "e-mail duplicado na instituição" "$(req $A POST /usuarios '{"nome":"X","email":"aluno2@a.com","listaPerfilId":3,"listaUnidadeId":1,"listaEstadoCivilId":1}' | jget error.fields.email)" "'Já cadastrado na instituição.'"
NU=$(req $A POST /usuarios '{"nome":"Prof Novo","email":"Novo@A.com","cpf":"39053344705","listaPerfilId":2,"listaUnidadeId":1,"listaEstadoCivilId":1,"senha":"Hack1234","master":true}')
NUID=$(echo "$NU" | jget data.id)
ok "cria usuário (e-mail normalizado)" "$(echo "$NU" | jget data.email)" "'novo@a.com'"
ok "senha/master não são graváveis" "$(echo "$NU" | grep -ciE '"senha|"master"')" 0
ok "sem acesso definido ainda" "$(echo "$NU" | jget data.acessoDefinido)" 0
ok "admin não muda o próprio perfil" "$(code $A PUT /usuarios/1 '{"listaPerfilId":3}')" 422
ok "admin não se inativa" "$(code $A PUT /usuarios/1 '{"inativo":true}')" 422
ok "professor não lista usuários" "$(code $PR GET /usuarios)" 403
ok "convite enviado" "$(req $A POST /usuarios/$NUID/convite | jget data.message)" "'Convite enviado para novo@a.com.'"
LINK=$(grep -h 'novo@a.com' -l "$MAILDIR"/*.html | xargs grep -ohE 'token=[a-f0-9]{64}' | head -1 | cut -d= -f2)
ok "convite traz link de definição de senha" "${#LINK}" 64
ok "usuário define a senha pelo link" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $U/auth/redefinir-senha -H "$J" -d "{\"token\":\"$LINK\",\"novaSenha\":\"Professor2026\"}")" 200
ok "novo usuário entra" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $U/auth/login -H "$J" -d '{"email":"novo@a.com","senha":"Professor2026"}')" 200

echo "== professores"
PL=$(req $A GET /professores)
ok "lista professores" "$(echo "$PL" | jget meta.total)" 1
ok "senha legada do professor nunca sai" "$(echo "$PL" | grep -ciE 'senha|texto-puro')" 0

echo "== inscrição de aluno logado e rematrícula"
ok "pré-requisito não cumprido" "$(code $AL POST /me/inscricoes '{"cursoId":201}')" 422
ok "aluno com pré-requisito se inscreve" "$(code $ANA POST /me/inscricoes '{"cursoId":201}')" 201
ok "não duplica inscrição" "$(code $ANA POST /me/inscricoes '{"cursoId":201}')" 409
RM=$(req $ANA GET /me/rematricula)
ok "rematrícula disponível (valor do curso)" "$(echo "$RM" | jget data.0.valor)" 120
ok "rematricular" "$(code $ANA POST /me/rematricula '{"cursoId":200}')" 200
ok "não rematricula duas vezes" "$(code $ANA POST /me/rematricula '{"cursoId":200}')" 409
ok "curso sem rematrícula aberta" "$(code $ANA POST /me/rematricula '{"cursoId":201}')" 422

echo "== limite de tentativas"
limpa; for i in 1 2 3 4 5; do curl -s -o /dev/null -X POST "$U/publico/inscricoes" -H "$J" -H "$HOST" -d '{}'; done
ok "6ª inscrição na hora = 429" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$U/publico/inscricoes" -H "$J" -H "$HOST" -d '{}')" 429
limpa

if [ -n "$MYSQL" ]; then
  echo "== rotina de inatividade"
  sql "update USUARIO set INATIVO=0 where EMAIL in ('ana@x.com','bia@x.com'); update USUARIO_CURSO uc join USUARIO u on u.ID=uc.USUARIO_ID set uc.DATA_CADASTRO=DATE_SUB(NOW(), INTERVAL 2 YEAR) where u.EMAIL in ('ana@x.com','bia@x.com') and uc.CURSO_ID=200"
  ok "professor não roda rotina" "$(code $PR POST /rotinas/inatividade)" 403
  ok "inativa quem só tem curso vencido" "$(req $A POST /rotinas/inatividade | jget data.inativados)" 1
  ok "Bia (só curso vencido) inativa" "$(sql "select INATIVO from USUARIO where EMAIL='bia@x.com'")" 1
  ok "Ana (outro curso ativo) continua ativa" "$(sql "select INATIVO from USUARIO where EMAIL='ana@x.com'")" 0
fi

echo "== questionário por tipo de curso"
CA2=$(curl -s "$U/publico/cursos" -H "$HOST")
ok "curso EAD usa perfil ead" "$(echo "$CA2" | $P -r 'foreach(json_decode(stream_get_contents(STDIN),true)["data"] as $c) if($c["id"]==200) echo $c["formulario"];')" ead
ok "curso Kids usa perfil kids" "$(echo "$CA2" | $P -r 'foreach(json_decode(stream_get_contents(STDIN),true)["data"] as $c) if($c["id"]==210) echo $c["formulario"];')" kids
QK=',"questionario":{"igrejaKid":"Igreja Infantil","igrejaUfKid":"RJ","saudeGeral":"Boa","transtornoDoenca":true,"justificativaTranstorno":"TDAH","filiacao":"Maria e Jose","rgOrgaoEmissor":"DETRAN","rgDataEmissao":"2015-03-01","colunaInventada":"x","MASTER":1}'
RK=$(pub "$(pessoa 210 'Lia Kids' lia@x.com 98765432100 "$QK")")
IK=$(echo "$RK" | jget data.inscricaoId)
ok "inscrição Kids criada" "$([ -n "$IK" ] && [ "$IK" != "NULL" ] && echo y)" y
DK=$(req $A GET /inscricoes/$IK)
ok "igreja da criança gravada" "$(echo "$DK" | jget data.questionario.igrejaKid)" "'Igreja Infantil'"
ok "transtorno com justificativa" "$(echo "$DK" | jget data.questionario.justificativaTranstorno)" "'TDAH'"
ok "filiação vai para o usuário" "$(echo "$DK" | jget data.aluno.filiacao)" "'Maria e Jose'"
if [ -n "$MYSQL" ]; then
  ok "data do RG gravada" "$(sql "select substr(RG_DATA_EMISSAO,1,10) from USUARIO where EMAIL='lia@x.com'")" 2015-03-01
  ok "chave fora da lista ignorada (MASTER continua 0)" "$(sql "select MASTER from USUARIO where EMAIL='lia@x.com'")" 0
fi

rm -rf $TMP
echo; [ $FALHAS -eq 0 ] && echo "TODOS OS TESTES PASSARAM" || { echo "$FALHAS FALHA(S)"; exit 1; }
