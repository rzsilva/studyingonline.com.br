<?php

declare(strict_types=1);

namespace App\Integrations\Storage;

use App\Support\ApiException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Armazena uploads em storage/uploads (fora da raiz pública; web.config nega acesso direto).
 * Valida extensão + MIME real (finfo), limita tamanho e gera nome aleatório.
 */
final class LocalStorage
{
    /** extensão => MIMEs aceitos */
    private const PERMITIDOS = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'txt'  => ['text/plain'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'mp3'  => ['audio/mpeg'],
        'zip'  => ['application/zip'],
    ];

    public function __construct(private readonly string $root, private readonly int $maxBytes = 20 * 1024 * 1024)
    {
    }

    /**
     * @param string[]|null $somente restringe as extensões aceitas (ex.: só imagens)
     * @return string caminho relativo (guardar no banco com prefixo "local:")
     */
    public function save(UploadedFileInterface $file, int $instituicaoId, string $pasta, ?array $somente = null): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ApiException::validation(['arquivo' => 'Falha no envio do arquivo (tamanho máximo do servidor excedido?).']);
        }
        if (($file->getSize() ?? 0) > $this->maxBytes) {
            throw ApiException::validation(['arquivo' => 'Arquivo maior que ' . intdiv($this->maxBytes, 1048576) . ' MB.']);
        }
        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (!isset(self::PERMITIDOS[$ext]) || ($somente !== null && !in_array($ext, $somente, true))) {
            throw ApiException::validation(['arquivo' => 'Tipo de arquivo não permitido.']);
        }

        $pasta = preg_replace('/[^a-z0-9_-]/', '', strtolower($pasta));
        $relDir = "{$instituicaoId}/{$pasta}";
        $absDir = "{$this->root}/{$relDir}";
        if (!is_dir($absDir) && !mkdir($absDir, 0770, true) && !is_dir($absDir)) {
            throw new \RuntimeException('Não foi possível criar a pasta de uploads.');
        }
        $rel = "{$relDir}/" . bin2hex(random_bytes(16)) . ".{$ext}";
        $file->moveTo("{$this->root}/{$rel}");

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file("{$this->root}/{$rel}") ?: '';
        if (!in_array($mime, self::PERMITIDOS[$ext], true)) {
            @unlink("{$this->root}/{$rel}");
            throw ApiException::validation(['arquivo' => 'O conteúdo do arquivo não corresponde à extensão.']);
        }
        return $rel;
    }

    public function path(string $rel): string
    {
        // impede path traversal
        if (str_contains($rel, '..') || !preg_match('#^\d+/[a-z0-9_-]+/[a-f0-9]{32}\.[a-z0-9]+$#', $rel)) {
            throw ApiException::notFound();
        }
        $abs = "{$this->root}/{$rel}";
        if (!is_file($abs)) {
            throw ApiException::notFound('Arquivo não encontrado.');
        }
        return $abs;
    }

    public function delete(string $rel): void
    {
        try {
            @unlink($this->path($rel));
        } catch (ApiException) {
        }
    }
}
