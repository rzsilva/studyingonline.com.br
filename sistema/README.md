# Studying Online 2.0

Reescrita do sistema Studying Online (antes ASP.NET MVC + Web API .NET) em:

- **api/**: API REST em PHP 8.1+ (Slim 4, PDO/MySQL, JWT)
- **web/**: SPA em React 18 + TypeScript + Vite + Tailwind
- **public_html/**: o que vai para a raiz pública do site (build do React + ponte `/api`)

O banco é o **mesmo MySQL do sistema legado**. As migrations só *acrescentam* colunas e tabelas, então os dois sistemas podem rodar em paralelo durante a transição.

## Rodar localmente

Pré-requisitos: PHP 8.1+ (XAMPP: `D:\xampp\php\php.exe`), Node 20+, e o Composer (`tools/composer.phar` já incluso).

```powershell
# 1) API
cd api
php ..\tools\composer.phar install
copy .env.example .env      # preencha DB_* (base de HOMOLOGAÇÃO), JWT_SECRET e API_BASE_PATH=/api
php bin\migrate.php         # aplica migrations/*.sql pendentes
php -S localhost:8099 -t public

# 2) Web (outro terminal)
cd web
npm install
npm run dev                 # http://localhost:5173  (proxy /api -> localhost:8099)
```

Para gerar o `JWT_SECRET`, rode `php -r "echo bin2hex(random_bytes(32));"`.

Sem acesso a uma base de homologação, use `api/tests/fixtures/schema_minimo.sql`. Ele monta um esquema mínimo com usuários de exemplo; detalhes em `docs/DESENVOLVIMENTO.md`.

## Testes

```powershell
cd api;  php vendor\bin\phpunit
cd web;  npm run lint        # checagem de tipos
```

## Publicar (Locaweb Windows)

Rode `powershell -File build.ps1` e envie a pasta `dist\` por FTP. O passo a passo está em [`docs/DEPLOY_LOCAWEB.md`](docs/DEPLOY_LOCAWEB.md).

## Documentação

- [Arquitetura](docs/ARQUITETURA.md): estrutura, padrões e como adicionar um módulo
- [Segurança](docs/SEGURANCA.md): falhas do legado, o que foi corrigido e **credenciais a rotacionar**
- [Roadmap da migração](docs/ROADMAP.md): fases e status de cada módulo
- [Deploy Locaweb](docs/DEPLOY_LOCAWEB.md)
- [Desenvolvimento](docs/DESENVOLVIMENTO.md)
