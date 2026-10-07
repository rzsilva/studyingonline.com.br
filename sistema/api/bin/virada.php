<?php

declare(strict_types=1);

/**
 * Script da VIRADA (desligamento do sistema .NET legado). Ver docs/VIRADA.md.
 *
 *   php bin/virada.php              → só confere e mostra o que seria feito (não altera nada)
 *   php bin/virada.php --executar   → aplica (irreversível sem o backup)
 *
 * O que faz com --executar:
 *   1. cifra com SECRETS_KEY as credenciais em texto de CONTA_BANCARIA (GATEWAY_TOKEN_PROD/HOMO);
 *   2. aplica 900_remover_senha_texto e 901_limpar_senha_professor (apaga senhas em texto puro).
 * Pré-requisitos no .env: SECRETS_KEY (64 hex), SECRETS_ENCRYPT=true, LEGACY_CLEAR_PLAINTEXT=true.
 */

use App\Domain\Auth\PasswordHasher;
use App\Support\Database;
use App\Support\Segredo;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->load();
$settings = require dirname(__DIR__) . '/config/settings.php';

$executar = in_array('--executar', $argv, true);
$db = Database::connect($settings['db']);
$segredo = new Segredo($settings['segredos']['chave'], true);
$erros = 0;
$linha = static fn (string $s) => print($s . PHP_EOL);

$linha($executar ? '=== VIRADA: EXECUTANDO ===' : '=== VIRADA: simulação (nada será alterado; use --executar) ===');
$linha('Banco: ' . $settings['db']['name'] . ' @ ' . $settings['db']['host']);

/* ---------- 1. pré-requisitos ---------- */
$linha("\n[1] Pré-requisitos");
$checks = [
    'SECRETS_KEY válida (64 hex)' => Segredo::chaveValida($settings['segredos']['chave']),
    'SECRETS_ENCRYPT=true' => $settings['segredos']['cifrar'],
    'LEGACY_CLEAR_PLAINTEXT=true' => $settings['auth']['legacy_clear_plaintext'],
    'APP_ENV=production' => $settings['env'] === 'production',
    'migrations 001-004 aplicadas' => (int) $db->query(
        "SELECT COUNT(*) FROM SCHEMA_MIGRATION WHERE ARQUIVO IN ('001_seguranca.sql','002_comunidade.sql','003_secretaria.sql','004_financeiro.sql')"
    )->fetchColumn() === 4,
];
foreach ($checks as $nome => $ok) {
    $linha(sprintf('  %s %s', $ok ? 'ok  ' : 'FALTA', $nome));
    $erros += $ok ? 0 : 1;
}

/* ---------- 2. credenciais de gateway ---------- */
$linha("\n[2] Credenciais em CONTA_BANCARIA");
$tam = $db->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'CONTA_BANCARIA' AND COLUMN_NAME IN ('GATEWAY_TOKEN_PROD','GATEWAY_TOKEN_HOMO')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$pendentes = [];
foreach ($tam as $col => $max) {
    $rows = $db->query("SELECT ID, {$col} AS V FROM CONTA_BANCARIA WHERE {$col} IS NOT NULL AND {$col} <> ''")->fetchAll();
    foreach ($rows as $r) {
        if ($segredo->cifrado($r['V'])) {
            continue;
        }
        // tamanho do valor cifrado: prefixo + base64(12 + 16 + n)
        $previsto = 7 + 4 * (int) ceil((28 + strlen($r['V'])) / 3);
        if ($max !== null && $previsto > (int) $max) {
            $linha("  ERRO conta {$r['ID']} {$col}: cifrado terá {$previsto} caracteres, coluna aceita {$max}. Aumente a coluna (ALTER TABLE) antes.");
            $erros++;
            continue;
        }
        $pendentes[] = [$col, (int) $r['ID'], $r['V']];
    }
}
$linha('  credenciais em texto a cifrar: ' . count($pendentes));

/* ---------- 3. senhas em texto ---------- */
$linha("\n[3] Senhas em texto puro");
$semHash = (int) $db->query("SELECT COUNT(*) FROM USUARIO WHERE SENHA IS NOT NULL AND SENHA <> '' AND SENHA_HASH IS NULL")->fetchColumn();
$comHash = (int) $db->query("SELECT COUNT(*) FROM USUARIO WHERE SENHA IS NOT NULL AND SENHA_HASH IS NOT NULL")->fetchColumn();
$prof = (int) $db->query("SELECT COUNT(*) FROM LISTA_PROFESSOR WHERE SENHA IS NOT NULL")->fetchColumn();
$linha("  usuários com hash e senha em texto (texto será apagado): {$comHash}");
$linha("  usuários que nunca entraram no sistema novo (SEM hash): {$semHash}");
$linha('    → recebem hash agora, gerado da senha atual, para ninguém precisar redefinir');
$linha("  LISTA_PROFESSOR com senha em texto (não usada em login): {$prof}");

if (!$executar) {
    $linha("\n" . ($erros ? "{$erros} pendência(s): corrija antes de executar." : 'Tudo pronto. Faça o BACKUP e rode com --executar.'));
    exit($erros ? 1 : 0);
}
if ($erros) {
    $linha("\nAbortado: {$erros} pendência(s).");
    exit(1);
}

/* ---------- execução ---------- */
$linha("\n[4] Executando");
$db->beginTransaction();
try {
    $up = [];
    foreach ($pendentes as [$col, $id, $v]) {
        $up[$col] ??= $db->prepare("UPDATE CONTA_BANCARIA SET {$col} = ? WHERE ID = ?");
        $up[$col]->execute([$segredo->cifrar($v), $id]);
    }
    $linha('  credenciais cifradas: ' . count($pendentes));

    // quem nunca logou no sistema novo ganha o hash da senha atual (mesma regra da migração no login)
    $st = $db->query("SELECT ID, SENHA FROM USUARIO WHERE SENHA IS NOT NULL AND SENHA <> '' AND SENHA_HASH IS NULL");
    $h = $db->prepare('UPDATE USUARIO SET SENHA_HASH = ? WHERE ID = ?');
    $hasher = new PasswordHasher();
    $n = 0;
    foreach ($st->fetchAll() as $u) {
        $h->execute([$hasher->hash($u['SENHA']), $u['ID']]);
        $n++;
    }
    $linha("  hashes gerados: {$n}");

    $db->exec('UPDATE USUARIO SET SENHA = NULL WHERE SENHA_HASH IS NOT NULL');
    $db->exec('UPDATE LISTA_PROFESSOR SET SENHA = NULL WHERE SENHA IS NOT NULL');
    $reg = $db->prepare('INSERT IGNORE INTO SCHEMA_MIGRATION (ARQUIVO, APLICADO_EM) VALUES (?, NOW())');
    foreach (['900_remover_senha_texto.sql', '901_limpar_senha_professor.sql'] as $m) {
        $reg->execute([$m]);
    }
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    $linha('  FALHOU, nada foi alterado: ' . $e->getMessage());
    exit(1);
}
$linha("\nVirada concluída. Confira o login de um aluno, de um professor e um pagamento de teste.");
