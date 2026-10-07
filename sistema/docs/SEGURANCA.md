# Segurança

## AÇÃO URGENTE: rotacionar credenciais

Os segredos abaixo estão em texto puro no repositório do sistema legado (`D:\StudyingOnline`) e **devem ser considerados comprometidos**. Troque cada um no provedor e configure o novo valor só no `.env` do servidor (e no `Web.config` do legado enquanto ele estiver no ar, sem fazer commit).

| Segredo | Onde estava |
|---|---|
| Senha do MySQL `studyingonline.mysql.dbaas.com.br` | `Adaline.Webservice/Web.config` (connection string) |
| Usuário e senha do FTP `ftp.studyingonline.com.br` | `Adaline.Webservice/Web.config` |
| Token da API BoletoCloud | `Adaline.Webservice/Web.config`, `Adaline.Webclient/Web.config` |
| Chave da API Brevo | `Adaline.Webservice/Web.config` |
| Chaves reCAPTCHA (`tokenSite`/`tokenServer`) | `Adaline.Webclient/Web.config` |
| Chave e salt Rijndael do cookie | `Utilities/Geral.cs` (permitem forjar sessão de qualquer usuário no legado) |
| Senhas de professores | coluna `LISTA_PROFESSOR.SENHA` (texto puro): não é usada pelo sistema novo; limpar na Fase 4 |
| Tokens de gateway (MercadoPago etc.) | tabela `CONTA_BANCARIA.GATEWAY_TOKEN_PROD`: rotacionar. Ficam em texto no banco enquanto o legado (que os lê assim) estiver no ar; criptografar em repouso na virada (Fase 6) |

Depois, remova os segredos do histórico do git do legado (ex.: `git filter-repo`) ou trate o repositório como privado e sensível.

## Falhas do legado e como foram tratadas

| # | Falha no legado | Tratamento no novo sistema | Fase |
|---|---|---|---|
| 1 | Sessão forjável (cookie criptografado com chave fixa no código) | JWT assinado com segredo do `.env` + refresh token aleatório, guardado só como hash | 1 ✅ |
| 2 | Senha em texto puro no banco e dentro do cookie | Argon2id em `SENHA_HASH`; o cookie não carrega dados do usuário | 1 ✅ |
| 3 | "Esqueci a senha" envia a senha por e-mail | Link de uso único, válido por 60 min, guardado como hash | 1 ✅ |
| 4 | Sem limite de tentativas de login | Rate limit: 5 por e-mail+IP a cada 5 min e 30 por IP a cada 15 min | 1 ✅ |
| 5 | Mensagens de erro revelam se o e-mail existe | Mensagem genérica e tempo de resposta equivalente | 1 ✅ |
| 6 | CORS `*` | Lista de origens no `.env` (aceita `*.studyingonline.com.br`) | 1 ✅ |
| 7 | HTTP sem TLS (`AllowInsecureHttp`) | Redirect para HTTPS, HSTS, cookie `Secure` | 1 ✅ |
| 8 | Sem headers de segurança | CSP, X-Frame-Options, nosniff, Referrer-Policy (API e SPA) | 1 ✅ |
| 9 | Exceções engolidas ou detalhes expostos | `ErrorHandler`: mensagem genérica + código; detalhes só no log | 1 ✅ |
| 10 | Endpoints sem `[Authorize]` e sem checar posse (IDOR) | `AuthMiddleware` no grupo; tenant + `canAccessUser` em todo repositório. Na Fase 2, o CRUD genérico filtra sempre por instituição e valida que FKs pertencem à mesma instituição | 1–2 ✅ / próximos módulos |
| 11 | Cor da instituição injetada direto no CSS | Validação `#rgb`/`#rrggbb` (`safeColor`) | 1 ✅ |
| 12 | Nota da prova enviada pelo aluno e **gabarito (`CORRETA`) enviado ao navegador** | Aluno envia só as respostas; nota calculada no servidor (`CorrecaoProva`); gabarito nunca sai da API para o aluno; uma tentativa por prova | 2 ✅ |
| 13 | Webhooks de pagamento sem validação; webhook MercadoPago usava a conta de **qualquer** instituição e baixava título por número em todas as escolas | URL com instituição + token HMAC; o corpo só traz o id, que é consultado na API com a credencial da própria instituição; título casado por ID + instituição; eventos idempotentes em `PAGAMENTO_EVENTO` | 5 ✅ |
| 14 | Upload sem checagem de tipo, enviado por FTP sem criptografia | Lista de extensões + MIME real (finfo), 20 MB, nome aleatório, em `storage/` (fora do webroot), download só autenticado | 2 ✅ |
| 16 | Painel abria qualquer curso pelo ID; URL de vídeo bloqueado ia ao navegador | Matrícula verificada; URL só para vídeo liberado; progresso e prova validados contra a trilha no servidor | 2 ✅ |
| 17 | Mass assignment (EF recebia a entidade inteira do cliente) | Só colunas declaradas em `Resource::fields` são gravadas; `INSTITUICAO_ID` e o dono vêm do token | 2 ✅ |
| 18 | URLs `javascript:` em vídeos/links | Campos URL aceitam só `http(s)://` | 2 ✅ |
| 19 | Chat: remetente forjável, leitura de qualquer conversa, `GetUsuarios` devolvendo o USUARIO completo (com SENHA) | Remetente do token; participação verificada; contatos só com nome/foto/perfil; rate limit de 30 msg/min | 3 ✅ |
| 20 | Fórum sem checagem de autoria; `Edit` aceitava a entidade inteira | Autor ou equipe; só título/texto editáveis | 3 ✅ |
| 21 | Anotações de vídeo visíveis a todos | Coluna `USUARIO_ID` e filtro por dono para todos os perfis | 3 ✅ |
| 22 | Lista de colegas expunha dados pessoais | Só nome e foto | 3 ✅ |
| 23 | **Crítico:** `Inscricao/Edit` funcionava SEM login e gravava o USUARIO inteiro do cliente (trocar senha/perfil de qualquer usuário, virar admin) | Análise só por admin; só o status muda; dados do candidato não são editáveis por essa rota | 4 ✅ |
| 24 | Inscrição pública reaproveitava contas existentes e confiava na instituição do corpo | E-mail/CPF existente = 409 (entrar e se inscrever logado); instituição pelo host; honeypot + 5 inscrições/h por IP | 4 ✅ |
| 25 | Admin definia/via senhas dos usuários | Convite com link de uso único (72h); senha nunca passa pelo cadastro | 4 ✅ |
| 26 | Documentos de inscrição por FTP sem checagem | Só PDF/JPG/PNG com MIME real; upload público só com token HMAC da inscrição (24h); download só admin ou o próprio aluno | 4 ✅ |
| 27 | `LISTA_PROFESSOR.SENHA` em texto puro | Nunca lida nem devolvida; script `901_*.pending` para apagar | 4 (aplicar 901) |
| 28 | Credenciais dos gateways legíveis pela API/tela | Gravadas por endpoint próprio e **nunca** devolvidas (a tela só mostra "configurada"); troca auditada | 5 ✅ |
| 29 | Rotinas financeiras públicas e sem login (`GerarCobrancaAlunos`, `*AtualizarStatusContaReceber`) | Rotina diária só com `X-Rotinas-Token`; ações manuais só para admin; financeiro inteiro restrito ao admin | 5 ✅ |
| 30 | XML do PagSeguro lido sem proteção | `LIBXML_NONET` (sem entidades externas / XXE) | 5 ✅ |
| 31 | Planilha exportada podia carregar fórmulas (CSV injection) | Campos de texto iniciados por `= + - @` são neutralizados | 5 ✅ |
| 15 | MySQL sem SSL | `DB_SSL_CA` no `.env` quando o provedor oferecer | 1 (opcional) |

## Senhas durante a transição

Enquanto o sistema .NET estiver no ar, `LEGACY_CLEAR_PLAINTEXT=false`:

- No login, a senha em texto é conferida, o hash é gravado em `SENHA_HASH` e a coluna `SENHA` é **mantida** para o legado continuar autenticando.
- Ao trocar ou redefinir a senha no sistema novo, as duas colunas são atualizadas.

**Na virada definitiva:**
1. Coloque `LEGACY_CLEAR_PLAINTEXT=true` no `.env`.
2. Renomeie `migrations/900_remover_senha_texto.sql.pending` para `.sql` e rode `php bin/migrate.php`.
3. Usuários que nunca entraram no sistema novo ficam sem senha e usam "Esqueci minha senha".

## Checklist antes de cada publicação

- [ ] `.env` de produção: `APP_ENV=production`, `APP_DEBUG=false`, `COOKIE_SECURE=true`, sem `API_BASE_PATH`.
- [ ] `JWT_SECRET` exclusivo do ambiente, com 64 caracteres hex.
- [ ] `https://seu-dominio/api/../api/.env` e `/_app/.env` retornam 404/403.
- [ ] `composer audit` e `npm audit --omit=dev` sem vulnerabilidades altas. Os alertas atuais vêm só de ferramentas de build (esbuild/braces), que não vão para produção.
- [ ] Testar com os perfis aluno, professor e admin: o aluno recebe 403/404 em rotas de admin e em IDs de outros alunos.
