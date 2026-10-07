# Deploy na Locaweb (hospedagem Windows compartilhada)

## Requisitos do plano

- **PHP 8.1 ou superior**, ativado no painel da Locaweb (Painel > Hospedagem > PHP), com as extensões `pdo_mysql`, `mbstring`, `openssl`, `curl` e `sodium` (para Argon2id; sem ela, o sistema usa bcrypt automaticamente).
- **URL Rewrite do IIS:** já vem habilitado na Locaweb Windows.
- **Banco MySQL:** é o mesmo de hoje (`studyingonline.mysql.dbaas.com.br`).
- **SSL:** ative o certificado (Let's Encrypt grátis no painel) para o domínio e os subdomínios das instituições.

## 1. Gerar o pacote (na sua máquina)

```powershell
cd D:\xampp\htdocs\studyingonline\sistema
powershell -ExecutionPolicy Bypass -File build.ps1
```

O script cria a pasta `dist\` com:

```
dist\public_html\   build do React + web.config + api\index.php (ponte)
dist\api\           código PHP + vendor (SEM .env)
```

Se a hospedagem não permitir pastas fora da raiz pública, use:
`build.ps1 -AppInsidePublic`. A API vai para `public_html\_app\`, protegida por um `web.config` que nega qualquer acesso HTTP.

## 2. Enviar por FTP (use FTPS/SFTP)

**Atalho:** `publicar-sistema.bat` (na raiz do repositório) compila com `-AppInsidePublic` e envia ao FTP só o que mudou desde a última publicação, para a pasta `FTP_REMOTE_ROOT_SISTEMA` de `deploy/.env` (ex.: `/web/sistema`). Ele nunca sobrescreve o `_app/.env` do servidor nem os dados de `_app/storage`.

Envio manual:

| Local | Servidor |
|---|---|
| `dist\public_html\*` | pasta pública do site (na Locaweb: `web\` ou `wwwroot\`) |
| `dist\api\` | uma pasta **ao lado** da pública, com o nome `api` (ex.: `\api` e `\web` lado a lado) |

A ponte `public_html/api/index.php` procura o código em `../../api` e, se não achar, em `../_app`.

## 3. Criar o `.env` no servidor

Copie `api/.env.example` para `api/.env` e preencha:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sistema.studyingonline.com.br
DB_HOST=studyingonline.mysql.dbaas.com.br
DB_NAME=studyingonline
DB_USER=...
DB_PASS=...            # use a senha NOVA (veja SEGURANCA.md)
JWT_SECRET=...         # php -r "echo bin2hex(random_bytes(32));"
COOKIE_SECURE=true
LEGACY_CLEAR_PLAINTEXT=false
CORS_ORIGINS=https://*.studyingonline.com.br
BREVO_API_KEY=...
```

A pasta `api/storage` precisa de permissão de escrita para o usuário do IIS (no painel da Locaweb: Gerenciador de arquivos > Permissões).

## 4. Migrations

A hospedagem compartilhada não tem terminal. Rode a migration **da sua máquina**, apontando um `.env` local para o banco de produção:

```powershell
cd api
php bin\migrate.php --status
php bin\migrate.php
```

> Antes, faça backup do banco (painel da Locaweb > MySQL > Backup). As migrations só adicionam colunas e tabelas, mas o backup é obrigatório.

## 4b. Pagamentos e rotina diária (Fase 5)

No `.env` do servidor, acrescente:

```ini
# proxies da Adaline usados pelo legado (mesmos valores de adaline_api_boletocloud / adaline_api_gatewaypagamento do Web.config antigo)
BOLETOCLOUD_PROXY_URL=...
BOLETOCLOUD_API_TOKEN=...      # use o token NOVO (o antigo está exposto no repositório)
GATEWAY_PROXY_URL=...
MERCADOPAGO_API_URL=https://api.mercadopago.com
PUBLIC_API_URL=https://SEU_DOMINIO/api
ROTINAS_TOKEN=...              # php -r "echo bin2hex(random_bytes(24));"
```

**Credenciais de cada instituição:** são cadastradas na tela *Cadastros → Contas de recebimento* (ícone de chave). Não ficam no `.env`.

**MercadoPago:** na mesma tela, a URL de notificação aparece ao abrir a credencial. Cadastre essa URL no painel do MercadoPago (Webhooks → evento *Pagamentos*).

**Agendador de tarefas da Locaweb:** crie uma tarefa diária, de madrugada, que chame `POST https://SEU_DOMINIO/api/rotinas/diaria` com o header `X-Rotinas-Token: <ROTINAS_TOKEN>`. Se o agendador não permitir header, rode `php bin/rotinas.php` da sua máquina apontando para o banco de produção. A rotina faz duas coisas:

- dá baixa nos pagamentos de boleto e cartão de todas as instituições;
- inativa os alunos cujo curso terminou.

**Antes de ligar:** teste cada provedor com credenciais de sandbox ou homologação. A integração foi validada apenas contra o simulador (`api/tests/mock/gateways.php`).

## 4c. Cobrança Adaline e cifragem (Fase 6)

```ini
ADALINE_OPERADORES=email1@adaline.com.br,email2@adaline.com.br   # usuários MASTER que operam o Painel Adaline
ADALINE_BOLETOCLOUD_CONTA_TOKEN=...   # conta BoletoCloud da Adaline (token NOVO)
ADALINE_TARIFA_BOLETO=2.50            # tarifa repassada por boleto emitido pelas escolas
SECRETS_KEY=...                       # php -r "echo bin2hex(random_bytes(32));"  (guarde cópia fora do servidor)
SECRETS_ENCRYPT=false                 # true só na virada (docs/VIRADA.md)
```

## 5. Conferir

- `https://SEU_DOMINIO/api/health` deve responder `{"data":{"status":"ok"}}`.
- `https://SEU_DOMINIO/` deve mostrar a tela de login com a cor e o logo da instituição.
- `https://SEU_DOMINIO/minha-conta`, ao recarregar a página (F5), não pode dar 404 (regra SPA do web.config).
- O `.env` não pode ser acessível por HTTP.

## Estratégia de transição

1. Publique primeiro em um subdomínio de homologação (ex.: `novo.studyingonline.com.br`) apontando para o **mesmo banco**. Os dois sistemas convivem.
2. À medida que cada fase do [ROADMAP](ROADMAP.md) for concluída, os usuários passam a usar as telas novas.
3. Na Fase 6, aponte os subdomínios das instituições para o sistema novo e desligue o legado (veja "Na virada definitiva" em SEGURANCA.md).
