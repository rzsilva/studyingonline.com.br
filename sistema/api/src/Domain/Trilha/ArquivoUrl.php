<?php

declare(strict_types=1);

namespace App\Domain\Trilha;

/**
 * ARQUIVO.URL pode ser:
 *  - URL absoluta do legado (arquivos no FTP antigo) -> usada como está
 *  - "local:<caminho>" de upload feito no sistema novo -> baixado via API autenticada
 */
final class ArquivoUrl
{
    public const PREFIX = 'local:';

    public static function publica(?string $url, int $id): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        return str_starts_with($url, self::PREFIX) ? "/arquivos/{$id}/download" : $url;
    }
}
