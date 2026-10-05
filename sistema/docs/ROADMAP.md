# Roadmap da migração

Legenda: ✅ concluído · 🔜 próximo · ⏳ pendente

| Fase | Escopo | Status |
|---|---|---|
| 0 | Esqueleto API/Web, `.env`, web.config do IIS, testes, script de build/deploy | ✅ |
| 1 | Login, refresh, logout, recuperar/trocar senha, instituição por host (white-label), `/me`, menu por perfil, layout | ✅ |
| 2 | Acadêmico: cursos, disciplinas, módulos, vídeo-aulas, aulas presenciais, material (upload), provas/questões, notas, estágio, atendimento, montar turma, **painel EAD do aluno com trilha sequencial** | ✅ |
| 3 | Avisos (com imagem e feed na home), Fórum, Chat, anotações de vídeo, Painel presencial, extrato financeiro do aluno | ✅ |
| 4 | Secretaria: inscrição pública, matrícula, rematrícula, Usuários, Professores, e-mails | 🔜 |
| 5 | Financeiro: contas a receber/pagar/fixas, conta bancária, cobranças, BoletoCloud/MercadoPago/Vindi/PagSeguro, webhooks, 2ª via, remessa, relatórios PDF/Excel | ⏳ |
| 6 | Site público da instituição, relatórios, cobrança Adaline, virada definitiva e desligamento do legado | ⏳ |

## Fase 2: o que foi entregue

| Tela (menu) | Rota | API |
|---|---|---|
| Painel ONLINE (aluno) | `/painel/online`, `/painel/online/:cursoId` | `GET /painel/cursos[/{id}]`, `POST /painel/videos/{id}/progresso`, `POST /painel/provas/{id}/respostas` |
| Cursos | `/cursos` | `/cursos` (CRUD) |
| Módulos e trilha (**nova**) | `/modulos` | `/modulos`: ordem e duração mínima de cada módulo |
| Disciplinas | `/disciplinas` | `/disciplinas` (catálogo `LISTA_DISCIPLINA`) |
| Aulas online / presenciais | `/video-aulas`, `/aulas` | `/videos`, `/aulas` |
| Material | `/arquivos` | `/arquivos` + `POST /arquivos/{id}/upload`, `GET /arquivos/{id}/download` |
| Provas | `/provas` | `/provas`, `/questoes`, `POST /provas/{id}/copiar` |
| Montar turma | `/turmas` | `/cursos/{id}/alunos` (listar, adicionar, remover, trocar) |
| Notas por curso/disciplina | `/notas/curso`, `/notas/disciplina` | `GET/PUT /notas/modulos/{id}` |
| Notas por aluno / boletim | `/notas/aluno` (e aba "Boletim" do aluno) | `GET /notas/alunos/{id}?cursoId=` |
| Horas de estágio | `/estagios` | `/estagios` |
| Atendimento | `/agendamento` | `/agendamentos` (aluno vê e cria só os próprios) |

## Fase 3: o que foi entregue

| Tela | Rota | API |
|---|---|---|
| Avisos (admin) e feed na página inicial | `/avisos`, `/` | `/avisos` (CRUD), `POST/GET /avisos/{id}/imagem` |
| Fórum por disciplina | `/forum`, `/forum/:id` | `/forum/topicos`, `/forum/topicos/{id}/respostas`, `/forum/respostas/{id}` |
| Chat 1:1 (polling a cada 5 s, contador no menu) | `/chat` | `/chat/contatos`, `GET/POST /chat/{contato}`, `/chat/nao-lidas` |
| Anotações pessoais (aba Aulas do painel EAD) | — | `/anotacoes` |
| Painel presencial | `/painel/presencial`, `/painel/presencial/:cursoId` | `/painel-presencial/cursos[/{id}]` |
| Extrato financeiro do aluno (**novo**) | `/meu-financeiro` | `GET /me/financeiro` |

Migration `002_comunidade.sql`: `VIDEO_ANOTACAO.USUARIO_ID`, `CHAT_MENSAGENS.LIDA_EM` e índices.

**FAQ:** a tabela `FAQ` do legado só tem `ID`, `INSTITUICAO_ID` e `DATA_CADASTRO`, sem pergunta nem resposta. Não havia conteúdo para migrar, então ficou fora. Se quiserem um FAQ, ele é um CRUD novo de cerca de 20 linhas.

## Mudanças de comportamento intencionais

| Item | Legado | Novo | Motivo |
|---|---|---|---|
| Pendência financeira | Qualquer título não pago, **inclusive parcelas futuras**, bloqueava o painel do aluno | Só títulos **vencidos** e não pagos | Alunos em dia eram bloqueados |
| Esqueci a senha | Gerava uma senha e mandava por e-mail | Link de redefinição de uso único | Segurança |
| Política de senha | Nenhuma | Mínimo de 8 caracteres, com letras e números (só para senhas novas) | Segurança |
| Sessão | Cookie de 10h | Access token de 15 min com renovação automática por 14 dias; trocar a senha encerra todas as sessões | Segurança |
| Correção da prova | Navegador recebia o gabarito e enviava a nota pronta | Servidor corrige; aluno envia só as respostas | O aluno podia ver as respostas e forjar a nota |
| Refazer prova | API aceitava reenvio (só a tela bloqueava) | Uma única tentativa (409 no reenvio) | Integridade |
| Painel do aluno | Qualquer aluno abria qualquer curso da instituição pelo ID | Só cursos em que está matriculado | IDOR |
| Aluno inativo ou com pendência | Só o item do menu sumia | A API também bloqueia o painel (403) | Regra aplicada no servidor |
| URL do vídeo bloqueado | Enviada ao navegador | Só é enviada quando o vídeo está liberado | Burlar a trilha |
| Curso sem média definida | Todo aluno ficava **reprovado** (comparação com nulo) | Média nula = 0 | Bug do legado |
| Chat | Remetente vinha do navegador; qualquer conversa podia ser lida pelo ID; WebSocket (não funciona em hospedagem compartilhada) | Remetente do token; só participantes leem; polling de 5 s; contador de não lidas real | Segurança / hospedagem |
| Anotações de vídeo | Sem dono: as anotações de um aluno apareciam para todos | Pessoais. As antigas (sem dono) não aparecem para ninguém no sistema novo | Privacidade |
| Fórum | Qualquer usuário editava ou apagava qualquer post | Só o autor; admin e professor moderam | Segurança |
| Extrato financeiro | Aba do painel presencial, escondida de quem tinha pendência | Item "Financeiro" sempre visível ao aluno, inclusive com pendência, para que ele possa regularizar | UX |
| Colegas de turma | Cadastro completo dos colegas (e-mail, telefone etc.) | Só nome e foto | LGPD |
| Status da nota lançada pelo professor | Escolhido manualmente | Automático pela média (maior entre nota e recuperação), com opção de definir manualmente | Menos erro de lançamento |

### Mantidos do legado, a confirmar com a coordenação
- **Prova de recuperação:** só o perfil Administrador pode aplicá-la (como `ALLOW_RECUPERACAO` no legado). Na prática o aluno não faz a recuperação sozinho. Se a regra desejada for "aluno reprovado pode fazer a recuperação uma vez", basta trocar a checagem em `PainelService::responderProva`.
- **Prova ativa por módulo:** quando houver mais de uma, vale a mais antiga (como `PROVA[0]` no legado).

## Referências do legado por fase

- **Fase 4:** `InscricaoController.cs` (a partir da linha 159), `UsuarioCursoController.cs` (Rematricular), `Scripts/{Inscricao,Usuario,Professor}.js`
- **Fase 5:** `Adaline.Webclient/Controllers/ContasReceberController.cs`, `ArquivoRetornoController.cs`, `MercadoPagoController.cs`, `AdalineBoletoCloudController.cs`
- **Fora do escopo:** apps Xamarin e o WinForms `UploadArquivos` podem ser apontados para a API nova depois da Fase 4.
