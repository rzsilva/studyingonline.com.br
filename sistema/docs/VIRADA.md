# Virada: desligar o sistema .NET legado

A virada **não foi executada**. Este documento prepara a operação, e a data é decisão da coordenação. Enquanto o legado estiver no ar, os dois sistemas usam o mesmo banco. Por isso, as senhas e as credenciais dos gateways continuam em texto puro: o legado precisa delas nesse formato.

## O que muda na virada

| Item | Antes (convivência) | Depois |
|---|---|---|
| Senhas em `USUARIO.SENHA` | texto puro mantido junto com `SENHA_HASH` | apagadas, fica só o hash Argon2id |
| `LISTA_PROFESSOR.SENHA` | texto puro (não usada para login) | apagada |
| `CONTA_BANCARIA.GATEWAY_TOKEN_PROD/HOMO` | texto puro | cifrado com AES-256-GCM (`enc:v1:...`) |
| DNS `*.studyingonline.com.br` | aponta para o IIS do legado | aponta para o novo `public_html` |

Quem nunca entrou no sistema novo **não perde o acesso**: o script gera o hash a partir da senha atual antes de apagar o texto.

## Checklist

### Uma semana antes
- [ ] Publicar o sistema novo num subdomínio de homologação e testar com os perfis admin, professor e aluno ([DEPLOY_LOCAWEB.md](DEPLOY_LOCAWEB.md)).
- [ ] Validar cada gateway com credenciais de sandbox: BoletoCloud, Vindi, PagSeguro e MercadoPago.
- [ ] Trocar todas as credenciais da lista de [SEGURANCA.md](SEGURANCA.md#ação-urgente-rotacionar-credenciais).
- [ ] Gerar a `SECRETS_KEY` (`php -r "echo bin2hex(random_bytes(32));"`) e guardar uma cópia num cofre de senhas, **fora do servidor**. Sem ela, as credenciais cifradas não podem ser lidas.
- [ ] Avisar as escolas sobre a janela de manutenção (sugestão: madrugada, cerca de 1 hora).
- [ ] Confirmar no painel do MercadoPago a nova URL de webhook de cada escola (tela *Contas de recebimento*).

### No dia (janela de manutenção)
1. [ ] Colocar o legado em manutenção, ou parar o site no IIS, para ninguém gravar no banco durante a troca.
2. [ ] **Backup completo do MySQL** (painel da Locaweb, MySQL, Backup) e download do arquivo.
3. [ ] No `.env` do sistema novo:
   ```ini
   APP_ENV=production
   LEGACY_CLEAR_PLAINTEXT=true
   SECRETS_KEY=<chave gerada>
   SECRETS_ENCRYPT=true
   ```
4. [ ] Simular o script, que não altera nada: `php bin/virada.php`. Todas as linhas devem aparecer como `ok`. Se aparecer `ERRO ... coluna aceita N`, aumente a coluna antes de seguir:
   `ALTER TABLE CONTA_BANCARIA MODIFY GATEWAY_TOKEN_PROD VARCHAR(1000);`
5. [ ] Executar: `php bin/virada.php --executar`. Tudo roda numa única transação: se algo falhar, nada é alterado.
6. [ ] Apontar o DNS ou o binding do IIS dos domínios das escolas para o sistema novo.
7. [ ] Conferir:
   - login de um aluno, um professor e um admin;
   - emissão de um boleto de teste e a 2ª via;
   - rotina diária: `POST /api/rotinas/diaria` deve responder `pagos`, `erros` e `faturasAdalinePagas`;
   - Painel Adaline (`/cobrancas`) com as faturas em aberto.
8. [ ] Agendar a rotina diária no agendador de tarefas da Locaweb.

### Depois
- [ ] Desligar o site do legado no IIS e o FTP antigo.
- [ ] Remover os segredos do histórico do repositório legado ou arquivá-lo como sensível.
- [ ] Acompanhar o log (`api/storage/logs`) por uma semana.

## Plano de rollback

Para voltar ao legado:

| Situação | Ação |
|---|---|
| Problema **antes** do passo 5 | Basta voltar o DNS ou o IIS para o legado. Nada no banco mudou. |
| Problema **depois** do passo 5, nas primeiras horas | Restaurar o backup do passo 2 e voltar o DNS. Pagamentos e cadastros feitos no meio-tempo precisam ser relançados à mão. Consulte `AUDITORIA` e `PAGAMENTO_EVENTO` para saber o que foi feito. |
| Problema depois de dias | Não restaure o backup, porque a perda seria grande. Corrija no sistema novo. Se o legado for indispensável, decifre as credenciais com a `SECRETS_KEY` (classe `App\Support\Segredo::abrir`) e peça aos usuários "Esqueci minha senha", já que o legado não lê `SENHA_HASH`. |

**Pontos de não retorno:** a remoção das senhas em texto e a perda da `SECRETS_KEY`. Por isso o backup do passo 2 e a cópia da chave fora do servidor são obrigatórios.

## Testado

O script foi executado numa cópia da base de testes:
- 4 credenciais cifradas e lidas de volta corretamente;
- 7 senhas em texto removidas;
- 1 hash gerado para um usuário que nunca tinha entrado;
- a segunda execução não encontrou nada pendente.
