<?php

declare(strict_types=1);

/**
 * Publica no FTP de produção o sistema JÁ COMPILADO (sistema/dist/public_html,
 * gerado por "build.ps1 -AppInsidePublic") na pasta remota FTP_REMOTE_ROOT_SISTEMA.
 *
 * Uso (normalmente via publicar-sistema.bat, na raiz do repo):
 *   php deploy/ftp-sistema.php                   # lista o que seria publicado (dry-run)
 *   php deploy/ftp-sistema.php --apply           # publica de verdade
 *   php deploy/ftp-sistema.php --apply --delete  # também apaga no servidor o que não existe mais no dist
 *
 * Como dist/ não fica no git, o que mudou é descoberto por conteúdo: o servidor
 * guarda um manifesto {caminho: sha1} em _app/.deploy-manifest.json (pasta negada
 * por HTTP pelo web.config) e só o que difere dele é enviado.
 *
 * Nunca tocados: _app/.env (só é enviado se o servidor ainda não tiver um) e os
 * dados de _app/storage/.
 *
 * Código de saída 2 = nada para publicar.
 */

const SAIDA_NADA_A_PUBLICAR = 2;
const MANIFESTO = '_app/.deploy-manifest.json';
const ENV_REMOTO = '_app/.env';

$origem = dirname(__DIR__) . '/sistema/dist/public_html';

function falhar(string $mensagem): never
{
    fwrite(STDERR, $mensagem . "\n");
    exit(1);
}

/** Lê deploy/.env (formato CHAVE=valor, # para comentários). */
function lerEnv(string $arquivo): array
{
    $valores = [];
    if (!is_file($arquivo)) {
        return $valores;
    }
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
            continue;
        }
        [$chave, $valor] = array_map('trim', explode('=', $linha, 2));
        $valores[$chave] = trim($valor, "\"'");
    }
    return $valores;
}

/** Arquivos que o deploy gerencia (os demais ficam como estão no servidor). */
function gerenciado(string $caminho): bool
{
    if ($caminho === ENV_REMOTO || $caminho === MANIFESTO) {
        return false;
    }
    if (str_starts_with($caminho, '_app/storage/')) {
        return in_array(basename($caminho), ['.gitkeep', 'web.config'], true);
    }
    return true;
}

/** _app primeiro, index.html por último: ninguém recebe um index que aponta para assets ainda não enviados. */
function prioridade(string $caminho): int
{
    return match (true) {
        str_starts_with($caminho, '_app/') => 0,
        $caminho === 'index.html' => 2,
        default => 1,
    };
}

function criarDiretorioRemoto($conexao, string $caminhoRemoto): void
{
    $atual = '';
    foreach (explode('/', trim($caminhoRemoto, '/')) as $parte) {
        $atual .= '/' . $parte;
        if (@ftp_chdir($conexao, $atual) === false) {
            @ftp_mkdir($conexao, $atual);
        }
    }
}

$aplicar = in_array('--apply', $argv, true);
$apagarNoServidor = in_array('--delete', $argv, true);

if (!is_file($origem . '/index.html') || !is_file($origem . '/_app/bootstrap/app.php')) {
    falhar("Pacote não encontrado em sistema/dist/public_html (ou gerado sem -AppInsidePublic).\n"
        . 'Rode antes: powershell -ExecutionPolicy Bypass -File sistema\build.ps1 -AppInsidePublic');
}

$env = lerEnv(__DIR__ . '/.env');
$ftp = [
    'host' => $env['FTP_HOST'] ?? '',
    'porta' => (int) ($env['FTP_PORT'] ?? 21),
    'usuario' => $env['FTP_USER'] ?? '',
    'senha' => $env['FTP_PASS'] ?? '',
    'ssl' => filter_var($env['FTP_SSL'] ?? false, FILTER_VALIDATE_BOOL),
    'passivo' => filter_var($env['FTP_PASSIVE'] ?? true, FILTER_VALIDATE_BOOL),
    'raiz_remota' => '/' . trim($env['FTP_REMOTE_ROOT_SISTEMA'] ?? '', '/'),
];

if ($ftp['host'] === '' || $ftp['usuario'] === '' || $ftp['raiz_remota'] === '/') {
    falhar('Configure FTP_HOST, FTP_USER, FTP_PASS e FTP_REMOTE_ROOT_SISTEMA (ex.: /web/sistema) em deploy/.env.');
}

// Arquivos locais e seus hashes
$locais = [];
$iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($origem, FilesystemIterator::SKIP_DOTS));
foreach ($iterador as $arquivo) {
    if ($arquivo->isFile()) {
        $caminho = str_replace('\\', '/', substr($arquivo->getPathname(), strlen($origem) + 1));
        if (gerenciado($caminho)) {
            $locais[$caminho] = sha1_file($arquivo->getPathname());
        }
    }
}

echo "Conectando em {$ftp['host']}:{$ftp['porta']}...\n";
$conexao = $ftp['ssl']
    ? @ftp_ssl_connect($ftp['host'], $ftp['porta'], 15)
    : @ftp_connect($ftp['host'], $ftp['porta'], 15);
if ($conexao === false) {
    falhar('Não foi possível conectar ao servidor FTP.');
}
if (!@ftp_login($conexao, $ftp['usuario'], $ftp['senha'])) {
    falhar('Login FTP falhou. Confira FTP_USER/FTP_PASS em deploy/.env.');
}
ftp_pasv($conexao, $ftp['passivo']);

$remoto = static fn (string $caminho): string => $ftp['raiz_remota'] . '/' . $caminho;

// Manifesto da última publicação (ausente = primeira publicação: envia tudo)
$manifesto = [];
$buffer = fopen('php://temp', 'w+b');
if (@ftp_fget($conexao, $buffer, $remoto(MANIFESTO), FTP_BINARY)) {
    rewind($buffer);
    $manifesto = json_decode((string) stream_get_contents($buffer), true) ?: [];
}
fclose($buffer);

$paraEnviar = array_keys(array_filter($locais, static fn (string $hash, string $c) => ($manifesto[$c] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));
$paraApagar = array_values(array_filter(array_keys(array_diff_key($manifesto, $locais)), 'gerenciado'));
usort($paraEnviar, static fn (string $a, string $b) => [prioridade($a), $a] <=> [prioridade($b), $b]);

// .env de produção: só na primeira vez (nunca sobrescreve o do servidor)
$enviarEnv = is_file($origem . '/' . ENV_REMOTO) && @ftp_size($conexao, $remoto(ENV_REMOTO)) < 0;
if ($enviarEnv) {
    array_unshift($paraEnviar, ENV_REMOTO);
}

echo "Origem: sistema/dist/public_html\n";
echo "Destino (FTP): {$ftp['raiz_remota']}\n";
echo $manifesto === [] ? "Sem manifesto no servidor: primeira publicação, todos os arquivos serão enviados.\n\n" : "\n";

if ($paraEnviar === [] && $paraApagar === []) {
    ftp_close($conexao);
    echo "Nada para publicar: o servidor já está igual ao pacote compilado.\n";
    exit(SAIDA_NADA_A_PUBLICAR);
}

if ($paraEnviar !== []) {
    echo 'Arquivos a enviar (' . count($paraEnviar) . "):\n";
    foreach ($paraEnviar as $caminho) {
        echo "  + {$caminho}" . ($caminho === ENV_REMOTO ? ' (servidor ainda não tem .env)' : '') . "\n";
    }
}
if ($paraApagar !== []) {
    echo "\nArquivos que não existem mais no pacote (" . count($paraApagar) . "):\n";
    foreach ($paraApagar as $caminho) {
        echo "  - {$caminho}" . ($apagarNoServidor ? ' (será apagado no servidor)' : ' (mantido no servidor — use --delete para apagar)') . "\n";
    }
}

if (!$aplicar) {
    ftp_close($conexao);
    echo "\nModo dry-run: nada foi enviado. Rode com --apply para publicar de verdade.\n";
    exit(0);
}

echo "\n";
$falhas = [];

foreach ($paraEnviar as $caminho) {
    criarDiretorioRemoto($conexao, dirname($remoto($caminho)));
    if (@ftp_put($conexao, $remoto($caminho), $origem . '/' . $caminho, FTP_BINARY)) {
        echo "  OK  {$caminho}\n";
    } else {
        echo "  ERRO {$caminho}\n";
        $falhas[] = $caminho;
    }
}

$novoManifesto = $locais;
if ($apagarNoServidor) {
    foreach ($paraApagar as $caminho) {
        if (@ftp_delete($conexao, $remoto($caminho))) {
            echo "  APAGADO  {$caminho}\n";
        } else {
            echo "  ERRO AO APAGAR  {$caminho}\n";
            $falhas[] = $caminho;
        }
    }
} else {
    // Mantidos no servidor: continuam no manifesto para poderem ser apagados depois.
    foreach ($paraApagar as $caminho) {
        $novoManifesto[$caminho] = $manifesto[$caminho];
    }
}

if ($falhas === []) {
    $buffer = fopen('php://temp', 'w+b');
    fwrite($buffer, json_encode($novoManifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rewind($buffer);
    if (!@ftp_fput($conexao, $remoto(MANIFESTO), $buffer, FTP_BINARY)) {
        echo "\nAviso: não foi possível gravar o manifesto; a próxima publicação enviará tudo de novo.\n";
    }
    fclose($buffer);
}

ftp_close($conexao);

if ($falhas !== []) {
    echo "\nConcluído com " . count($falhas) . " falha(s) (manifesto não atualizado; rode de novo):\n";
    foreach ($falhas as $caminho) {
        echo "  - {$caminho}\n";
    }
    exit(1);
}

echo "\nPublicação do sistema concluída com sucesso.\n";
