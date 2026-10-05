# Desenvolvimento local

## Base de testes sem acesso à homologação

Três arquivos criam só as tabelas e colunas que o sistema novo usa até agora:

- `api/tests/fixtures/schema_minimo.sql`: usuários, instituições e financeiro mínimo (Fase 1).
- `api/tests/fixtures/schema_academico.sql`: tabelas acadêmicas (Fase 2).
- `api/tests/fixtures/schema_comunidade.sql`: avisos, fórum, chat, anotações e extrato (Fase 3).

Usuários de teste:

| E-mail | Senha | Perfil | Instituição |
|---|---|---|---|
| admin@a.com | senha123 | Administrador | Escola A (ativa) |
| aluno@a.com | aluno123 | Aluno | Escola A |
| aluno@b.com | aluno123 | Aluno | Escola B (**inativa**: login bloqueado) |
| aluno2@a.com | aluno123 | Aluno | Escola A (usado nos e2e) |
| prof@a.com | prof1234 | Professor | Escola A |

As senhas estão em texto puro de propósito, para testar a migração para hash no primeiro login.

Para usar uma instância temporária do MariaDB do XAMPP na porta 3307 (sem mexer no MySQL que já está rodando):

```bash
D:/xampp/mysql/bin/mysql_install_db.exe --datadir=C:/temp/so-mysql --port=3307
D:/xampp/mysql/bin/mysqld.exe --defaults-file=C:/temp/so-mysql/my.ini --bind-address=127.0.0.1
mysql -uroot -P3307 -h127.0.0.1 -e "CREATE DATABASE so_dev CHARACTER SET utf8mb4; CREATE USER so_dev@'127.0.0.1' IDENTIFIED BY 'devpass'; GRANT ALL ON so_dev.* TO so_dev@'127.0.0.1';"
mysql --default-character-set=utf8mb4 -uroot -P3307 -h127.0.0.1 so_dev < api/tests/fixtures/schema_minimo.sql
mysql --default-character-set=utf8mb4 -uroot -P3307 -h127.0.0.1 so_dev < api/tests/fixtures/schema_academico.sql
mysql --default-character-set=utf8mb4 -uroot -P3307 -h127.0.0.1 so_dev < api/tests/fixtures/schema_comunidade.sql
```

Sem `--default-character-set=utf8mb4`, o cliente do Windows grava os acentos dos dados de teste errado (ex.: "In├¡cio").

No `.env`: `DB_PORT=3307`, `DB_NAME=so_dev`, `DB_USER=so_dev`, `DB_PASS=devpass`, `API_BASE_PATH=/api`. Depois rode `php bin/migrate.php`.

## Testes manuais da Fase 1 (já executados)

| Cenário | Esperado |
|---|---|
| Senha errada | 401, mensagem genérica |
| Login legado (texto puro) | 200; `SENHA_HASH` preenchido com `$argon2id$` |
| Refresh | Novo access token; o refresh token anterior é revogado |
| Reuso de refresh token revogado | 401 e **todas** as sessões do usuário revogadas |
| Instituição inativa + aluno | 403 |
| Pendência: só parcela futura / parcela vencida | `false` / `true` |
| Troca de senha fraca / senha atual errada / ok | 422 / 422 / 200 e sessões revogadas |
| Redefinir senha: reuso do link | 400 `invalid_token` |
| 6 logins errados seguidos | 429 na 6ª tentativa |
| Origem CORS não listada | sem `Access-Control-Allow-Origin` |

## Testes automatizados

Com a API rodando (`php -S localhost:8099 -t public`) numa base de testes **recém-criada**:

```bash
php vendor/bin/phpunit                                   # 23 testes unitários (auth, HTTP, trilha, correção de prova)
PHP=D:/xampp/php/php.exe bash tests/e2e/fase2.sh        # 57 verificações: acadêmico e trilha
PHP=D:/xampp/php/php.exe bash tests/e2e/fase3.sh        # 48 verificações: comunidade (rodar depois do fase2)
```

**`fase2.sh` cobre:**
- cadastro e validação;
- isolamento entre instituições (FK e IDs de outra escola);
- permissões por perfil;
- trilha: liberação de módulo e vídeo, URL oculta, gabarito nunca enviado;
- prova corrigida no servidor (ignora `nota` enviada) e sem reenvio;
- notas com status automático;
- agendamento do aluno;
- upload (PDF aceito, PHP disfarçado recusado) e download autenticado;
- integridade (exclusão com dependências = 409) e cópia de prova.

**`fase3.sh` cobre:**
- **Avisos:** isolamento por instituição, URL não gravável pelo JSON, upload só de imagem.
- **Fórum:** autoria, moderação, HTML tratado como texto, visualizações, lista de respostas.
- **Chat:** remetente vindo do token, privacidade da conversa, contador de não lidas, polling incremental, aluno não conversa com aluno.
- **Anotações:** pessoais.
- **Painel presencial:** exige matrícula; colegas só com nome e foto.
- **Extrato:** só o do próprio aluno.

As telas também foram verificadas em navegador headless (Chrome + puppeteer-core), com aluno, admin e professor, no desktop e no celular (390px):

- sem erros de console e sem 5xx;
- menus corretos por perfil;
- rotas de admin bloqueadas para o aluno;
- formulários, fórum, chat, anotações e painel presencial funcionando.

### Observação sobre acentos no Windows

O `curl` do Git Bash envia em ANSI os acentos que vão dentro de argumentos `-d`. Por isso os scripts usam escapes JSON (ex.: `\u00e1` para "á"). A API responde 400 `invalid_json` a corpos fora de UTF-8, em vez de ignorá-los.
