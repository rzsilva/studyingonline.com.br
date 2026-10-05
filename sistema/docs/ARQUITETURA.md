# Arquitetura

```
Navegador ──► public_html/ (IIS)
               ├─ index.html + assets/   SPA React (rotas resolvidas no cliente)
               └─ api/index.php ──► api/bootstrap/app.php (Slim 4)
                                         │
                       Middlewares: CORS → SecurityHeaders → Erros → Rotas → Auth/Role
                                         │
                       Controller (fino) → Service (regras) → Repository (SQL/PDO)
                                         │
                                       MySQL (mesma base do legado)
```

## Comparação com o legado

| Legado (.NET) | Novo |
|---|---|
| Webclient MVC chama a Web API no servidor e o jQuery chama a API direto | SPA React chama só a API |
| Sessão = cookie com JSON do usuário criptografado com chave fixa (forjável) | JWT HS256 de 15 min em memória + refresh token rotativo em cookie HttpOnly |
| `[Authorize]` comentado; filtro por instituição feito só em parte | `AuthMiddleware` em todo o grupo autenticado; repositórios recebem `instituicaoId` do token |
| Senha em texto puro | Argon2id, migrada no primeiro login |
| `catch { return null; }` | `ErrorHandler`: JSON padronizado, detalhes só no log, com código de erro para suporte |
| EF6 Database-First | PDO com prepared statements, conexão aberta só quando necessária (`Support/Connection`) |
| Segredos no `Web.config` versionado | `.env` fora do webroot e fora do git |

## Estrutura da API (`api/`)

```
bootstrap/
  app.php         monta o Slim (middlewares, base path, erros)
  container.php   injeção de dependências (PHP-DI)
  routes.php      TODAS as rotas
config/settings.php   lê o .env
src/
  Domain/<Modulo>/    Services + Repositories por módulo (Auth, Usuario, Instituicao, ...)
  Http/Controllers/   recebem a request, validam, chamam o domínio e respondem com Json::ok
  Http/Middleware/    Auth, Role, Cors, SecurityHeaders
  Integrations/       serviços externos (Mail/Brevo; depois BoletoCloud, MercadoPago, Vindi, PagSeguro)
  Support/            Connection, Json, Validator, ApiException, RateLimiter
migrations/           SQL incremental, aplicado por bin/migrate.php
storage/              logs, cache, rate limit e uploads (bloqueado por web.config)
```

### Contrato HTTP

- Sucesso: `{"data": ..., "meta": {...}}`
- Erro: `{"error": {"code": "validation", "message": "...", "fields": {"campo": "msg"}}}`
- Códigos usados:
  - 401: não autenticado
  - 403: sem permissão
  - 404: não encontrado, inclusive registro de outra instituição (assim não se revela que ele existe)
  - 422: validação
  - 429: limite de tentativas

### Regras obrigatórias para novos módulos

1. **Tenant:** todo `SELECT/UPDATE/DELETE` filtra por `INSTITUICAO_ID = $user->instituicaoId`. Nunca aceite `INSTITUICAO_ID` vindo do cliente.
2. **Perfil:** use `RoleMiddleware::only(Perfil::Administrador, ...)` nas rotas, ou `$user->is(...)` no service.
3. **Posse:** quando o aluno acessa dados por ID, verifique `$user->canAccessUser($usuarioId)`.
4. **Validação:** use `Validator::make($body)->required(...)->validate()` antes de tocar no banco.
5. **Saída:** nunca devolva `SENHA`, `SENHA_HASH`, tokens de gateway ou segredos. Liste as colunas explicitamente (veja `UsuarioRepository::PUBLIC_COLUMNS`).
6. **Regras de negócio** ficam no Service. **Notas de prova e valores financeiros** são sempre calculados no servidor.
7. **Auditoria:** ações financeiras e administrativas gravam na tabela `AUDITORIA`.

### CRUD genérico (`Support/Crud`)

A maioria dos cadastros é declarada, não programada. Um `Resource` descreve a tabela, os campos graváveis e as regras de acesso. `CrudController::routes()` cria `GET/POST/PUT/DELETE`, e o `CrudRepository` garante as regras de segurança:

- **Isolamento:** toda consulta filtra `t.INSTITUICAO_ID` pelo usuário do token.
- **Colunas permitidas:** só os `Field` declarados são gravados (sem mass assignment).
- **Chaves estrangeiras:** `Field::ref()` confere se o registro referenciado existe *na mesma instituição*.
- **Tipos:** conversão por tipo (decimal com vírgula, data, hora, URL só `http(s)`, enum).
- **Dono:** `ownerColumn` faz o aluno ver só os próprios registros; `ownerCanWrite` e `staffOnlyColumns` controlam o que ele pode gravar.
- **Erros previsíveis:** exclusão bloqueada por FK vira 409; coluna NOT NULL vira 422; toda escrita é auditada.

```php
// src/Domain/Academico/Resources.php
public static function disciplinas(): Resource
{
    $r = new Resource('LISTA_DISCIPLINA', 'Disciplina');
    $r->fields(F::string('VALOR', 200)->required(), F::text('DESCRICAO'));
    $r->search = ['t.VALOR'];
    return $r;            // JSON usa camelCase: VALOR -> valor, LISTA_DISCIPLINA_ID -> listaDisciplinaId
}

// bootstrap/routes.php, dentro do grupo autenticado
CrudController::routes($g, '/disciplinas', Resources::disciplinas());
```

No frontend, a página correspondente também é só configuração (`components/CrudPage.tsx`): colunas, campos, filtros e permissões. Veja `pages/academico/CadastroPages.tsx`.

Regras que não cabem num CRUD ficam em services próprios:

- `Domain/Trilha/TrilhaService`: regra da trilha sequencial, pura e testada.
- `PainelService`: painel do aluno.
- `CorrecaoProva`: correção da prova no servidor.
- `NotaService`: lançamento em lote e boletim.
- `MatriculaService`: montar turma.

## Estrutura do frontend (`web/src`)

```
api/client.ts        fetch + token em memória + refresh automático em 401 (uma vez só, mesmo com várias requests)
auth/AuthProvider    sessão: recupera pelo cookie ao abrir, login/logout, /me
theme/ThemeProvider  white-label: cor, título e favicon da instituição pelo subdomínio
lib/navigation.ts    menu e regras de visibilidade por perfil (porte de _Navigation.cshtml)
layouts/             AuthLayout (login) e AppShell (sidebar responsiva + header)
pages/               telas
components/ui.tsx    Button, Input, Alert, Card...
```

- Dados do servidor: TanStack Query (`useQuery`/`useMutation`).
- Formulários: react-hook-form + zod. As regras de senha espelham `PasswordPolicy` do PHP.
- A cor primária vem da instituição (`--primary`, em RGB) e é usada como `bg-primary`, `text-primary` etc.
- Para criar uma tela nova: crie `pages/<Modulo>Page.tsx` e registre a rota em `App.tsx` no lugar do `EmMigracaoPage` daquela URL.
