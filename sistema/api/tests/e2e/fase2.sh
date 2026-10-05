#!/usr/bin/env bash
# Testes de ponta a ponta da Fase 2 (acadêmico + trilha). Requer a API rodando com a base de
# testes (tests/fixtures/schema_minimo.sql + schema_academico.sql) e API_BASE_PATH=/api.
# Uso: bash tests/e2e/fase2.sh [http://localhost:8099/api]
U=${1:-localhost:8099/api}; J='Content-Type: application/json'; P=${PHP:-php}
TMP=$(mktemp -d)
FALHAS=0

jget() { $P -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){$d=$d[$k]??null;} echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }
login() { curl -s -X POST $U/auth/login -H "$J" -d "{\"email\":\"$1\",\"senha\":\"$2\"}" | jget data.accessToken | tr -d "'"; }
req() { if [ -n "$4" ]; then curl -s -X $2 "$U$3" -H "$J" -H "Authorization: Bearer $1" -d "$4"; else curl -s -X $2 "$U$3" -H "Authorization: Bearer $1"; fi; }
code() { if [ -n "$4" ]; then curl -s -o /dev/null -w '%{http_code}' -X $2 "$U$3" -H "$J" -H "Authorization: Bearer $1" -d "$4"; else curl -s -o /dev/null -w '%{http_code}' -X $2 "$U$3" -H "Authorization: Bearer $1"; fi; }
ok() { if [ "$2" == "$3" ]; then echo "  OK    $1"; else echo "  FALHA $1 (esperado $3, veio $2)"; FALHAS=$((FALHAS+1)); fi; }

A=$(login admin@a.com senha123); AL=$(login aluno2@a.com aluno123); PR=$(login prof@a.com prof1234)

echo "== cadastro (admin)"
C=$(req $A POST /cursos '{"nome":"Teologia EAD","listaTipoCursoId":1,"listaUnidadeId":1,"media":"7","valor":"150,50"}' | jget data.id)
ok "cria curso" "$([ "$C" -gt 0 ] 2>/dev/null && echo y)" y
ok "valor decimal com vírgula" "$(req $A GET /cursos/$C | jget data.valor)" "'150.50'"
ok "validação de campos" "$(code $A POST /cursos '{"nome":""}')" 422
ok "FK de outra instituição recusada" "$(req $A POST /cursos '{"nome":"X","listaTipoCursoId":2,"listaUnidadeId":1}' | jget error.fields.listaTipoCursoId)" "'Registro relacionado não encontrado.'"
ok "curso de outra instituição = 404" "$(code $A GET /cursos/99)" 404
ok "alterar curso de outra instituição = 404" "$(code $A PUT /cursos/99 '{"nome":"hack"}')" 404
ok "módulo em curso de outra instituição" "$(code $A POST /modulos '{"cursoId":99,"listaDisciplinaId":1}')" 422
D1=$(req $A POST /disciplinas '{"valor":"Hermen\u00eautica"}' | jget data.id)
D2=$(req $A POST /disciplinas '{"valor":"Grego"}' | jget data.id)
M1=$(req $A POST /modulos "{\"cursoId\":$C,\"listaDisciplinaId\":$D1,\"listaProfessorId\":1,\"ordem\":1,\"duracaoDias\":0}" | jget data.id)
M2=$(req $A POST /modulos "{\"cursoId\":$C,\"listaDisciplinaId\":$D2,\"ordem\":2,\"duracaoDias\":30}" | jget data.id)
V1=$(req $A POST /videos "{\"disciplinaId\":$M1,\"titulo\":\"Aula 1\",\"url\":\"https://youtu.be/a1\",\"youtube\":true,\"ordem\":1}" | jget data.id)
V2=$(req $A POST /videos "{\"disciplinaId\":$M1,\"titulo\":\"Aula 2\",\"url\":\"https://youtu.be/a2\",\"ordem\":2}" | jget data.id)
req $A POST /videos "{\"disciplinaId\":$M2,\"titulo\":\"Aula Grego\",\"url\":\"https://youtu.be/g1\"}" >/dev/null
ok "URL javascript: recusada" "$(code $A POST /videos "{\"disciplinaId\":$M1,\"titulo\":\"x\",\"url\":\"javascript:alert(1)\"}")" 422
PV=$(req $A POST /provas "{\"disciplinaId\":$M1}" | jget data.id)
Q1=$(req $A POST /questoes "{\"provaId\":$PV,\"questao\":\"2+2?\",\"opcao1\":\"4\",\"opcao2\":\"5\",\"correta\":\"A\",\"valor\":5}" | jget data.id)
Q2=$(req $A POST /questoes "{\"provaId\":$PV,\"questao\":\"Capital?\",\"opcao1\":\"Rio\",\"opcao2\":\"SP\",\"opcao3\":\"Bras\u00edlia\",\"correta\":\"C\",\"valor\":5}" | jget data.id)
ok "gabarito inválido recusado" "$(code $A POST /questoes "{\"provaId\":$PV,\"questao\":\"x\",\"opcao1\":\"a\",\"opcao2\":\"b\",\"correta\":\"Z\",\"valor\":1}")" 422
ok "lista de provas com total de questões" "$(req $A GET "/provas?cursoId=$C" | jget data.0.totalQuestoes)" 2
ok "matricular aluno" "$(req $A POST /cursos/$C/alunos '{"usuarioIds":[4]}' | jget data.adicionados)" 1
ok "matricular não-aluno recusado" "$(code $A POST /cursos/$C/alunos '{"usuarioIds":[1]}')" 422
ok "alunos do curso" "$(req $A GET /cursos/$C/alunos | jget data.0.nome)" "'Pedro Aluno'"

echo "== permissões"
ok "aluno não lista cursos" "$(code $AL GET /cursos)" 403
ok "professor lista cursos" "$(code $PR GET /cursos)" 200
ok "professor não cria curso" "$(code $PR POST /cursos '{"nome":"x","listaTipoCursoId":1,"listaUnidadeId":1}')" 403
ok "aluno não vê questões com gabarito" "$(code $AL GET "/questoes?provaId=$PV")" 403
ok "aluno não matricula" "$(code $AL POST /cursos/$C/alunos '{"usuarioIds":[4]}')" 403

echo "== painel do aluno (trilha)"
ok "meus cursos" "$(req $AL GET /painel/cursos | jget data.0.nome)" "'Teologia EAD'"
ok "curso sem matrícula = 404" "$(code $AL GET /painel/cursos/99)" 404
T=$(req $AL GET /painel/cursos/$C)
ok "módulo 1 liberado" "$(echo "$T" | jget data.modulos.0.liberado)" true
ok "módulo 2 bloqueado" "$(echo "$T" | jget data.modulos.1.liberado)" false
ok "vídeo 2 bloqueado" "$(echo "$T" | jget data.modulos.0.videos.1.liberado)" false
ok "URL do vídeo bloqueado oculta" "$(echo "$T" | jget data.modulos.0.videos.1.url)" NULL
ok "gabarito não enviado ao aluno" "$(echo "$T" | grep -ci correta)" 0
ok "prova indisponível sem assistir" "$(echo "$T" | jget data.modulos.0.prova.disponivel)" false
ok "NOTA 'cursando' criada ao liberar" "$(echo "$T" | jget data.modulos.0.nota.status)" "'CURSANDO'"
ok "progresso em vídeo bloqueado" "$(code $AL POST /painel/videos/$V2/progresso '{"evento":"ended"}')" 403
ok "prova antes de assistir" "$(code $AL POST /painel/provas/$PV/respostas "{\"respostas\":{\"$Q1\":\"A\"}}")" 403
req $AL POST /painel/videos/$V1/progresso '{"evento":"progress","tempo":"01:20"}' >/dev/null
ok "assistiu vídeo 1" "$(req $AL POST /painel/videos/$V1/progresso '{"evento":"ended"}' | jget data.assistido)" true
ok "assistiu vídeo 2" "$(req $AL POST /painel/videos/$V2/progresso '{"evento":"ended"}' | jget data.assistido)" true
R=$(req $AL POST /painel/provas/$PV/respostas "{\"respostas\":{\"$Q1\":\"A\",\"$Q2\":\"B\"},\"nota\":10}")
ok "nota calculada no servidor (ignora nota enviada)" "$(echo "$R" | jget data.nota)" 5
ok "reprovado (média 7)" "$(echo "$R" | jget data.status)" "'REPROVADO'"
ok "prova não pode ser refeita" "$(code $AL POST /painel/provas/$PV/respostas "{\"respostas\":{\"$Q1\":\"A\",\"$Q2\":\"C\"}}")" 409
ok "aluno não faz recuperação" "$(code $AL POST /painel/provas/$PV/respostas "{\"respostas\":{\"$Q1\":\"A\"},\"recuperacao\":true}")" 403
T=$(req $AL GET /painel/cursos/$C)
ok "módulo 2 segue bloqueado (reprovado)" "$(echo "$T" | jget data.modulos.1.liberado)" false
ok "resultado por questão após a prova" "$(echo "$T" | jget data.modulos.0.prova.questoes.1.resultado.resposta)" "'B'"

echo "== notas (professor)"
ok "professor lança nota" "$(req $PR PUT /notas/modulos/$M1 '{"notas":[{"usuarioId":4,"nota1":5,"notaRecuperacao":"8,5","faltas":1}]}' | jget data.salvas)" 1
ok "status automático: aprovado pela recuperação" "$(req $PR GET /notas/modulos/$M1 | jget data.alunos.0.statusId)" 2
ok "nota para aluno fora do curso" "$(code $PR PUT /notas/modulos/$M1 '{"notas":[{"usuarioId":2,"nota1":5}]}')" 422
ok "módulo 2 libera após aprovação" "$(req $AL GET /painel/cursos/$C | jget data.modulos.1.liberado)" true
ok "boletim do próprio aluno" "$(req $AL GET "/notas/alunos/4?cursoId=$C" | jget data.disciplinas.0.resultado)" "'APROVADO'"
ok "boletim de outro aluno" "$(code $AL GET "/notas/alunos/2?cursoId=$C")" 403

echo "== agendamento (aluno dono)"
AGA=$(req $AL GET /agendamentos | jget meta.total)
AG=$(req $AL POST /agendamentos '{"listaCategoriaAgId":1,"data":"2026-10-20","hora":"14:30","listaSituacaoAgId":2,"usuarioId":1}')
ok "aluno agenda para si (ignora usuarioId)" "$(echo "$AG" | jget data.usuarioId)" 4
ok "aluno não define a situação" "$(echo "$AG" | jget data.listaSituacaoAgId)" 1
AGT=$(req $A GET /agendamentos | jget meta.total)
req $A POST /agendamentos '{"usuarioId":2,"listaCategoriaAgId":2,"data":"2026-10-21","hora":"09:00"}' >/dev/null
ok "aluno vê só os próprios" "$(req $AL GET /agendamentos | jget meta.total)" $((AGA+1))
ok "admin vê todos" "$(req $A GET /agendamentos | jget meta.total)" $((AGT+1))
ok "aluno não cria estágio" "$(code $AL POST /estagios "{\"cursoId\":$C,\"usuarioId\":4}")" 403

echo "== upload de material"
AR=$(req $A POST /arquivos "{\"listaDisciplinaId\":$D1,\"titulo\":\"Apostila\"}" | jget data.id)
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n' > $TMP/apostila.pdf
printf '<?php echo 1;' > $TMP/ruim.pdf
ok "upload de PDF" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $U/arquivos/$AR/upload -H "Authorization: Bearer $A" -F arquivo=@$TMP/apostila.pdf)" 200
ok "PHP disfarçado de PDF recusado" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $U/arquivos/$AR/upload -H "Authorization: Bearer $A" -F arquivo=@$TMP/ruim.pdf)" 422
ok "aluno baixa o material" "$(curl -s $U/arquivos/$AR/download -H "Authorization: Bearer $AL" | head -c 8)" "%PDF-1.4"
ok "material aparece na trilha" "$(req $AL GET /painel/cursos/$C | jget data.modulos.0.arquivos.0.url)" "'/arquivos/$AR/download'"
ok "aluno não faz upload" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $U/arquivos/$AR/upload -H "Authorization: Bearer $AL" -F arquivo=@$TMP/apostila.pdf)" 403

echo "== integridade"
ok "excluir curso com módulos = 409" "$(code $A DELETE /cursos/$C)" 409
ok "copiar prova para o módulo 2" "$(req $A POST /provas/$PV/copiar "{\"moduloIds\":[$M2]}" | jget data.copias)" 1
ok "trocar para curso de outra instituição = 404" "$(code $A POST /cursos/$C/alunos/4/trocar '{"cursoDestinoId":99}')" 404

rm -rf $TMP
echo; [ $FALHAS -eq 0 ] && echo "TODOS OS TESTES PASSARAM" || { echo "$FALHAS FALHA(S)"; exit 1; }
