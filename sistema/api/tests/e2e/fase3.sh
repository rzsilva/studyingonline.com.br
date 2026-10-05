#!/usr/bin/env bash
# Testes de ponta a ponta da Fase 3 (avisos, fórum, chat, anotações, presencial, financeiro).
# Rodar DEPOIS de fase2.sh na mesma base (usa o curso/módulos criados lá).
U=${1:-localhost:8099/api}; J='Content-Type: application/json'; P=${PHP:-php}
TMP=$(mktemp -d); FALHAS=0
jget() { $P -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){$d=$d[$k]??null;} echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }
login() { curl -s -X POST $U/auth/login -H "$J" -d "{\"email\":\"$1\",\"senha\":\"$2\"}" | jget data.accessToken | tr -d "'"; }
req() { if [ -n "$4" ]; then curl -s -X $2 "$U$3" -H "$J" -H "Authorization: Bearer $1" -d "$4"; else curl -s -X $2 "$U$3" -H "Authorization: Bearer $1"; fi; }
code() { if [ -n "$4" ]; then curl -s -o /dev/null -w '%{http_code}' -X $2 "$U$3" -H "$J" -H "Authorization: Bearer $1" -d "$4"; else curl -s -o /dev/null -w '%{http_code}' -X $2 "$U$3" -H "Authorization: Bearer $1"; fi; }
ok() { if [ "$2" == "$3" ]; then echo "  OK    $1"; else echo "  FALHA $1 (esperado $3, veio $2)"; FALHAS=$((FALHAS+1)); fi; }

A=$(login admin@a.com senha123); AL=$(login aluno2@a.com aluno123); PR=$(login prof@a.com prof1234)
B=$(login aluno@b.com aluno123) # instituição inativa: login bloqueado (403) => token vazio

echo "== avisos"
ok "aluno vê avisos só da sua instituição" "$(req $AL GET /avisos | jget meta.total)" 1
AV=$(req $A POST /avisos '{"titulo":"Prova sexta","descricao":"Sala 3","url":"javascript:x"}' | jget data.id)
ok "url do aviso não é gravável pelo JSON" "$(req $A GET /avisos/$AV | jget data.url)" NULL
ok "aluno não publica aviso" "$(code $AL POST /avisos '{"titulo":"x"}')" 403
printf '\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82' > $TMP/img.png
cp $TMP/img.png $TMP/doc.pdf
ok "upload de imagem do aviso" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $U/avisos/$AV/imagem -H "Authorization: Bearer $A" -F arquivo=@$TMP/img.png)" 200
ok "aviso só aceita imagem" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $U/avisos/$AV/imagem -H "Authorization: Bearer $A" -F arquivo=@$TMP/doc.pdf)" 422
ok "aluno vê a imagem" "$(curl -s -o /dev/null -w '%{http_code}' $U/avisos/$AV/imagem -H "Authorization: Bearer $AL")" 200

echo "== fórum"
D=$(req $A GET /listas/disciplinas | jget data.0.id)
T=$(req $AL POST /forum/topicos "{\"disciplinaId\":$D,\"titulo\":\"D\u00favida\",\"descricao\":\"<script>alert(1)</script> como estudar?\"}" | jget data.id)
ok "aluno cria tópico" "$([ "$T" -gt 0 ] 2>/dev/null && echo y)" y
ok "tópico em disciplina inválida" "$(code $AL POST /forum/topicos '{"disciplinaId":99999,"titulo":"x","descricao":"y"}')" 422
ok "texto guardado como texto (sem execução)" "$(req $AL GET /forum/topicos/$T | jget data.descricao)" "'<script>alert(1)</script> como estudar?'"
R=$(req $PR POST /forum/topicos/$T/respostas '{"texto":"Leia o cap. 2"}' | jget data.id)
ok "professor responde" "$([ "$R" -gt 0 ] 2>/dev/null && echo y)" y
ok "aluno não edita resposta do professor" "$(code $AL PUT /forum/respostas/$R '{"texto":"hack"}')" 403
ok "aluno não exclui resposta alheia" "$(code $AL DELETE /forum/respostas/$R)" 403
ok "autor edita o próprio tópico" "$(code $AL PUT /forum/topicos/$T '{"titulo":"D\u00favida (editada)","descricao":"ok"}')" 204
TT=$(req $AL GET /forum/topicos/$T)
ok "visualizações contadas" "$(echo "$TT" | jget data.visualizacoes)" 2
ok "detalhe traz a lista de respostas" "$(echo "$TT" | jget data.respostas.0.texto)" "'Leia o cap. 2'"
ok "tópico marcado como editado" "$(echo "$TT" | jget data.editado)" true
ok "listagem por disciplina" "$(req $AL GET "/forum/topicos?disciplinaId=$D" | jget data.0.respostas)" 1
ok "professor modera (exclui resposta)" "$(code $PR DELETE /forum/respostas/$R)" 204
ok "tópico inexistente" "$(code $AL GET /forum/topicos/999999)" 404

echo "== chat"
CT=$(req $AL GET /chat/contatos)
ok "aluno não vê alunos nos contatos" "$(echo "$CT" | grep -c 'Maria Aluna')" 0
ok "contatos sem dados sensíveis" "$(echo "$CT" | grep -ci 'senha\|email\|cpf')" 0
ok "aluno não conversa com aluno" "$(code $AL POST /chat/2 '{"texto":"oi"}')" 403
ok "aluno envia à coordenação" "$(req $AL POST /chat/1 '{"texto":"Ol\u00e1, preciso de ajuda","usuarioSendId":5}' | jget data.minha)" true
ok "admin tem 1 não lida" "$(req $A GET /chat/nao-lidas | jget data.naoLidas)" 1
ok "/me reflete não lidas" "$(req $A GET /me | jget data.mensagensNaoLidas)" 1
ok "admin lê a conversa" "$(req $A GET /chat/4 | jget data.0.texto)" "'Olá, preciso de ajuda'"
ok "lida zera o contador" "$(req $A GET /chat/nao-lidas | jget data.naoLidas)" 0
ok "remetente veio do token (não do JSON)" "$(req $A GET /chat/4 | jget data.0.minha)" false
ok "professor não lê conversa aluno-admin" "$(req $PR GET /chat/4 | jget data)" "[]"
ok "mensagem vazia" "$(code $A POST /chat/4 '{"texto":"   "}')" 422
ok "contato de outra instituição" "$(code $A POST /chat/3 '{"texto":"oi"}')" 404
M1=$(req $A POST /chat/4 '{"texto":"Claro!"}' | jget data.id)
ok "polling incremental (depois=)" "$(req $AL GET "/chat/1?depois=$((M1-1))" | jget data.0.texto)" "'Claro!'"

echo "== anotações (pessoais)"
MOD=$(req $A GET /listas/modulos?cursoId=$(req $A GET /listas/cursos | jget data.0.id) | jget data.0.id)
N1=$(req $AL POST /anotacoes "{\"disciplinaId\":$MOD,\"video\":\"Aula 1\",\"posicao\":\"00:05:10\",\"descricao\":\"Rever exemplo\"}" | jget data.id)
ok "aluno anota" "$([ "$N1" -gt 0 ] 2>/dev/null && echo y)" y
req $PR POST /anotacoes "{\"disciplinaId\":$MOD,\"descricao\":\"nota do professor\"}" >/dev/null
ok "aluno vê só as suas" "$(req $AL GET "/anotacoes?moduloId=$MOD" | jget meta.total)" 1
ok "professor vê só as suas" "$(req $PR GET "/anotacoes?moduloId=$MOD" | jget meta.total)" 1
ok "professor não altera anotação do aluno" "$(code $PR PUT /anotacoes/$N1 '{"descricao":"x"}')" 404
ok "aluno exclui a sua" "$(code $AL DELETE /anotacoes/$N1)" 204

echo "== painel presencial e financeiro"
CP=$(req $A POST /cursos '{"nome":"Teologia Presencial","listaTipoCursoId":1,"listaUnidadeId":1,"tipoTurma":2}' | jget data.id)
MP=$(req $A POST /modulos "{\"cursoId\":$CP,\"listaDisciplinaId\":$D}" | jget data.id)
req $A POST /aulas "{\"disciplinaId\":$MP,\"titulo\":\"Aula inaugural\",\"dataAula\":\"2026-10-10\",\"inicio\":\"19:00\",\"termino\":\"21:00\"}" >/dev/null
ok "curso presencial ainda não aparece (sem matrícula)" "$(req $AL GET /painel-presencial/cursos | jget data)" "[]"
ok "aluno sem matrícula não abre" "$(code $AL GET /painel-presencial/cursos/$CP)" 404
req $A POST /cursos/$CP/alunos '{"usuarioIds":[4]}' >/dev/null
ok "aluno vê o curso presencial" "$(req $AL GET /painel-presencial/cursos | jget data.0.nome)" "'Teologia Presencial'"
PC=$(req $AL GET /painel-presencial/cursos/$CP)
ok "aulas do módulo" "$(echo "$PC" | jget data.modulos.0.aulas.0.titulo)" "'Aula inaugural'"
ok "colegas só com nome/foto" "$(echo "$PC" | jget data.colegas.0 | grep -ci 'email\|cpf\|senha')" 0
ok "curso EAD não aparece no presencial" "$(code $AL GET /painel-presencial/cursos/99)" 404
FIN=$(req $AL GET /me/financeiro)
ok "extrato: título futuro em aberto (não vencido)" "$(echo "$FIN" | jget data.0.vencido)" false
ok "extrato: título pago" "$(echo "$FIN" | jget data.1.pago)" true
ok "extrato só do próprio aluno" "$(echo "$FIN" | grep -o '"id"' | wc -l | tr -d ' ')" 2
ok "aluno de instituição inativa não loga" "$B" "NULL"

rm -rf $TMP
echo; [ $FALHAS -eq 0 ] && echo "TODOS OS TESTES PASSARAM" || { echo "$FALHAS FALHA(S)"; exit 1; }
