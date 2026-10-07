<?php

declare(strict_types=1);

/**
 * Publica no FTP de produção todos os arquivos alterados no repositório
 * (git status: modificados, novos e apagados) a partir da RAIZ do repo,
 * espelhando a raiz do Git 1:1 na raiz remota configurada em FTP_REMOTE_ROOT.
 *
 * Uso (normalmente via publicar.bat, na raiz do repo):
 *   php deploy/ftp-deploy.php              # lista o que seria publicado (dry-run)
 *   php deploy/ftp-deploy.php --apply       # publica de verdade
 *   php deploy/ftp-deploy.php --apply --delete   # também apaga no servidor os arquivos apagados localmente
 *   php deploy/ftp-deploy.php --listar-commits   # mostra os 5 últimos commits, numerados
 *   php deploy/ftp-deploy.php --commit=<n|hash> [--apply [--delete]]
 *       # em vez das alterações pendentes, publica os arquivos alterados num commit:
 *       # n = 1..5 da lista acima (1 = último) ou o hash (mínimo 4 caracteres).
 *       # O conteúdo enviado é o do commit (lido do git, não da pasta local), então
 *       # funciona mesmo com alterações pendentes e com commits antigos.
 *   php deploy/ftp-deploy.php --ultimo-commit ...   # atalho para --commit=1
 *
 * Código de saída 2 = nada para publicar (publicar.bat usa isso para oferecer
 * a publicação de um commit).
 *
 * Credenciais em deploy/.env (FTP_HOST, FTP_USER, FTP_PASS, ...) — nunca no
 * código; o .env fica fora do git (.gitignore). Arquivos de ferramenta (deploy/,
 * publicar.bat, README, .gitignore, .env), sistema/ (publicado à parte) e
 * data/ (mídias dos usuários) nunca são publicados.
 */

$raizRepo = trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse --show-toplevel 2>&1'));

if ($raizRepo === '' || !is_dir($raizRepo)) {
    fwrite(STDERR, "Não foi possível localizar a raiz do repositório git.\n");
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

$env = lerEnv(__DIR__ . '/.env');
$ftp = [
    'host' => $env['FTP_HOST'] ?? '',
    'porta' => (int) ($env['FTP_PORT'] ?? 21),
    'usuario' => $env['FTP_USER'] ?? '',
    'senha' => $env['FTP_PASS'] ?? '',
    'ssl' => filter_var($env['FTP_SSL'] ?? false, FILTER_VALIDATE_BOOL),
    'passivo' => filter_var($env['FTP_PASSIVE'] ?? true, FILTER_VALIDATE_BOOL),
    'raiz_remota' => '/' . trim($env['FTP_REMOTE_ROOT'] ?? '', '/'),
];

$aplicar = in_array('--apply', $argv, true);
$apagarNoServidor = in_array('--delete', $argv, true);
$listarCommits = in_array('--listar-commits', $argv, true);
$commitEscolhido = in_array('--ultimo-commit', $argv, true) ? '1' : null;
foreach ($argv as $argumento) {
    if (str_starts_with($argumento, '--commit=')) {
        $commitEscolhido = trim(substr($argumento, strlen('--commit=')));
    }
}

const SAIDA_NADA_A_PUBLICAR = 2;
const COMMITS_LISTADOS = 5;

function falhar(string $mensagem): never
{
    fwrite(STDERR, $mensagem . "\n");
    exit(1);
}

function caminhoIgnorado(string $caminhoRelativo): bool
{
    if (str_ends_with($caminhoRelativo, '.env') && (str_ends_with($caminhoRelativo, '/.env') || $caminhoRelativo === '.env')) {
        return true;
    }

    if (in_array($caminhoRelativo, ['publicar.bat', 'publicar-sistema.bat', 'README.md', '.gitignore'], true)) {
        return true;
    }

    // sistema/ é publicado à parte (subdomínio sistema.) e data/ são mídias dos usuários.
    return str_starts_with($caminhoRelativo, '.git/') || str_contains($caminhoRelativo, '/.git/')
        || str_starts_with($caminhoRelativo, 'deploy/') || str_starts_with($caminhoRelativo, 'sistema/')
        || str_starts_with($caminhoRelativo, 'data/');
}

/** @return array{upload: list<string>, apagar: list<string>} */
function arquivosAlterados(string $raizRepo): array
{
    // rtrim (não trim): o espaço à esquerda da 1ª linha é significativo — é
    // parte do código de status (ex.: " M caminho"), não espaço em branco solto.
    $saida = shell_exec('git -C ' . escapeshellarg($raizRepo) . ' status --porcelain=v1 -uall 2>&1');
    $linhas = array_filter(explode("\n", rtrim((string) $saida)));

    $upload = [];
    $apagar = [];

    foreach ($linhas as $linha) {
        $status = substr($linha, 0, 2);
        $resto = substr($linha, 3);

        if (str_contains($resto, ' -> ')) {
            [$antigo, $novo] = explode(' -> ', $resto, 2);
            $apagar[] = trim($antigo, '"');
            $resto = $novo;
        }

        $caminho = trim($resto, '"');

        // Diretório não rastreado (sem arquivos individuais no status ainda): expande.
        if (str_ends_with($caminho, '/')) {
            $listaDoDir = shell_exec(
                'git -C ' . escapeshellarg($raizRepo)
                . ' ls-files --others --exclude-standard -- ' . escapeshellarg($caminho) . ' 2>&1'
            );
            foreach (array_filter(explode("\n", trim((string) $listaDoDir))) as $arquivoDoDir) {
                $upload[] = $arquivoDoDir;
            }
            continue;
        }

        if (str_contains($status, 'D')) {
            $apagar[] = $caminho;
        } else {
            $upload[] = $caminho;
        }
    }

    $upload = array_values(array_unique(array_filter($upload, static fn (string $c) => !caminhoIgnorado($c))));
    $apagar = array_values(array_unique(array_filter($apagar, static fn (string $c) => !caminhoIgnorado($c))));

    return ['upload' => $upload, 'apagar' => $apagar];
}

/**
 * Executa o git sem passar pelo shell (argumentos não precisam de escape e a saída
 * vem em modo binário — necessário para ler o conteúdo de imagens do commit).
 *
 * @param list<string> $argumentos
 * @return array{0: int, 1: string, 2: string} [código de saída, stdout, stderr]
 */
function git(string $raizRepo, array $argumentos): array
{
    // core.quotePath=false: nomes com acento vêm como texto, não como escapes octais.
    $comando = array_merge(['git', '-c', 'core.quotePath=false', '-C', $raizRepo], $argumentos);
    $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($processo)) {
        falhar('Não foi possível executar o git.');
    }

    $saida = (string) stream_get_contents($pipes[1]);
    $erro = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($processo), $saida, $erro];
}

function listarUltimosCommits(string $raizRepo): void
{
    [, $saida] = git($raizRepo, [
        'log', '-' . COMMITS_LISTADOS, '--format=%h%x09%cd%x09%an%x09%s', '--date=format:%d/%m/%Y %H:%M',
    ]);

    echo 'Últimos ' . COMMITS_LISTADOS . " commits:\n";
    foreach (array_values(array_filter(explode("\n", rtrim($saida)))) as $indice => $linha) {
        [$hash, $data, $autor, $assunto] = array_pad(explode("\t", $linha, 4), 4, '');
        $numero = $indice + 1;
        echo "  {$numero}) {$hash}  {$data}  {$assunto} ({$autor})\n";
    }
}

/** "1".."5" (posição na lista, 1 = último) ou hash → hash completo do commit. */
function resolverCommit(string $raizRepo, string $valor): string
{
    if (preg_match('/^[1-' . COMMITS_LISTADOS . ']$/', $valor) === 1) {
        $referencia = 'HEAD~' . ((int) $valor - 1);
    } elseif (preg_match('/^[0-9a-f]{4,40}$/i', $valor) === 1) {
        $referencia = $valor;
    } else {
        falhar("Commit inválido: \"{$valor}\". Informe um número de 1 a " . COMMITS_LISTADOS . ' ou o hash do commit.');
    }

    [$codigo, $saida] = git($raizRepo, ['rev-parse', '--verify', '--quiet', $referencia . '^{commit}']);
    if ($codigo !== 0 || trim($saida) === '') {
        falhar("Commit não encontrado: \"{$valor}\".");
    }

    return trim($saida);
}

/**
 * Arquivos adicionados/modificados/renomeados/apagados no commit, em relação ao
 * primeiro pai (num merge, o que o merge trouxe para o branch). O primeiro commit
 * do repositório (sem pai) lista todos os seus arquivos.
 *
 * @return array{upload: list<string>, apagar: list<string>}
 */
function arquivosDoCommit(string $raizRepo, string $hash): array
{
    [$temPai] = git($raizRepo, ['rev-parse', '--verify', '--quiet', $hash . '^1']);
    [, $saida] = $temPai === 0
        ? git($raizRepo, ['diff', '--name-status', '-M', $hash . '^1', $hash])
        : git($raizRepo, ['diff-tree', '--root', '--no-commit-id', '--name-status', '-r', '-M', $hash]);
    $linhas = array_filter(explode("\n", rtrim($saida)));

    $upload = [];
    $apagar = [];

    foreach ($linhas as $linha) {
        $partes = explode("\t", $linha);
        $status = $partes[0][0] ?? '';

        if ($status === 'R' && count($partes) === 3) {
            $apagar[] = $partes[1];
            $upload[] = $partes[2];
        } elseif ($status === 'D') {
            $apagar[] = $partes[1];
        } elseif (isset($partes[1])) {
            $upload[] = end($partes);
        }
    }

    $upload = array_values(array_unique(array_filter($upload, static fn (string $c) => !caminhoIgnorado($c))));
    $apagar = array_values(array_unique(array_filter($apagar, static fn (string $c) => !caminhoIgnorado($c))));

    return ['upload' => $upload, 'apagar' => $apagar];
}

function criarDiretorioRemoto($conexao, string $caminhoRemoto): void
{
    $partes = explode('/', trim($caminhoRemoto, '/'));
    $atual = '';

    foreach ($partes as $parte) {
        $atual .= '/' . $parte;

        if (@ftp_chdir($conexao, $atual) === false) {
            @ftp_mkdir($conexao, $atual);
        }
    }
}

if ($listarCommits) {
    listarUltimosCommits($raizRepo);
    exit(0);
}

// Hash completo do commit publicado, ou null ao publicar as alterações pendentes.
$hashCommit = null;

if ($commitEscolhido !== null) {
    $hashCommit = resolverCommit($raizRepo, $commitEscolhido);
    ['upload' => $paraEnviar, 'apagar' => $paraApagar] = arquivosDoCommit($raizRepo, $hashCommit);
    [, $resumoCommit] = git($raizRepo, ['log', '-1', '--format=%h - %s (%an, %cd)', '--date=format:%d/%m/%Y %H:%M', $hashCommit]);
    $resumoCommit = trim($resumoCommit);

    if ($paraEnviar === [] && $paraApagar === []) {
        echo "Nada para publicar: o commit {$resumoCommit} não tem arquivos publicáveis.\n";
        exit(SAIDA_NADA_A_PUBLICAR);
    }

    echo "Commit: {$resumoCommit}\n";

    // Commit antigo: publicar volta no servidor a versão dele dos arquivos que
    // mudaram depois — avisa para não desfazer uma publicação mais nova sem querer.
    [$ehHead] = git($raizRepo, ['merge-base', '--is-ancestor', 'HEAD', $hashCommit]);
    if ($ehHead !== 0) {
        // Filtra no PHP: passar os caminhos ao git estoura o limite de linha de comando do Windows.
        [, $alteradosDepois] = git($raizRepo, ['log', '--format=', '--name-only', $hashCommit . '..HEAD']);
        $alteradosDepois = array_values(array_intersect(
            array_unique(array_filter(explode("\n", rtrim($alteradosDepois)))),
            array_merge($paraEnviar, $paraApagar)
        ));

        if ($alteradosDepois !== []) {
            echo "\nATENÇÃO: " . count($alteradosDepois) . " arquivo(s) deste commit foram alterados em commits posteriores.\n"
                . "Publicar envia a versão DESTE commit (mais antiga) e sobrescreve a do servidor:\n";
            foreach ($alteradosDepois as $caminho) {
                echo "  ! {$caminho}\n";
            }
        }
    }

    echo "\n";
} else {
    ['upload' => $paraEnviar, 'apagar' => $paraApagar] = arquivosAlterados($raizRepo);

    if ($paraEnviar === [] && $paraApagar === []) {
        echo "Nada para publicar: nenhuma alteração pendente no repositório.\n";
        exit(SAIDA_NADA_A_PUBLICAR);
    }
}

echo "Raiz do repositório: {$raizRepo}\n";
echo "Raiz remota (FTP): {$ftp['raiz_remota']}\n\n";

if ($paraEnviar !== []) {
    echo 'Arquivos a enviar (' . count($paraEnviar) . "):\n";
    foreach ($paraEnviar as $caminho) {
        echo "  + {$caminho}\n";
    }
}

if ($paraApagar !== []) {
    echo "\nArquivos apagados " . ($hashCommit !== null ? 'no commit' : 'localmente') . ' (' . count($paraApagar) . "):\n";
    foreach ($paraApagar as $caminho) {
        echo '  - ' . $caminho . ($apagarNoServidor ? ' (será apagado no servidor)' : ' (mantido no servidor — use --delete para apagar)') . "\n";
    }
}

if (!$aplicar) {
    echo "\nModo dry-run: nada foi enviado. Rode com --apply" . ($hashCommit !== null ? " --commit={$commitEscolhido}" : '') . " para publicar de verdade.\n";
    exit(0);
}

if ($ftp['host'] === '' || $ftp['usuario'] === '') {
    falhar("\nConfigure FTP_HOST, FTP_USER e FTP_PASS em deploy/.env antes de publicar.");
}

echo "\nConectando em {$ftp['host']}:{$ftp['porta']}...\n";

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
echo "Conectado.\n\n";

$falhas = [];

foreach ($paraEnviar as $caminho) {
    $local = $raizRepo . '/' . $caminho;
    $remoto = $ftp['raiz_remota'] . '/' . $caminho;
    $temporario = null;

    if ($hashCommit !== null) {
        // Conteúdo do commit exatamente como está no repositório (sem a conversão
        // para CRLF do checkout no Windows — o servidor é Linux), não o da pasta
        // local, que pode estar em outra versão.
        [$codigo, $conteudo] = git($raizRepo, ['cat-file', 'blob', $hashCommit . ':' . $caminho]);
        if ($codigo !== 0) {
            echo "  ERRO {$caminho} (não foi possível ler o arquivo no commit)\n";
            $falhas[] = $caminho;
            continue;
        }

        $temporario = tempnam(sys_get_temp_dir(), 'deploy_');
        file_put_contents($temporario, $conteudo);
        $local = $temporario;
    } elseif (!is_file($local)) {
        echo "  ~ {$caminho} (não existe mais localmente, pulando)\n";
        continue;
    }

    criarDiretorioRemoto($conexao, dirname($remoto));
    $enviado = @ftp_put($conexao, $remoto, $local, FTP_BINARY);

    if ($temporario !== null) {
        unlink($temporario);
    }

    if ($enviado) {
        echo "  OK  {$caminho}\n";
    } else {
        echo "  ERRO {$caminho}\n";
        $falhas[] = $caminho;
    }
}

if ($apagarNoServidor) {
    foreach ($paraApagar as $caminho) {
        $remoto = $ftp['raiz_remota'] . '/' . $caminho;

        if (@ftp_delete($conexao, $remoto)) {
            echo "  APAGADO  {$caminho}\n";
        } else {
            echo "  ERRO AO APAGAR  {$caminho}\n";
            $falhas[] = $caminho;
        }
    }
}

ftp_close($conexao);

if ($falhas !== []) {
    echo "\nConcluído com " . count($falhas) . " falha(s):\n";
    foreach ($falhas as $caminho) {
        echo "  - {$caminho}\n";
    }
    exit(1);
}

echo "\nPublicação concluída com sucesso.\n";
