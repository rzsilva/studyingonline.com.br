<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Limitador por janela fixa, gravado em arquivo (hospedagem compartilhada não tem Redis).
 */
final class RateLimiter
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
    }

    /** Registra uma tentativa; lança 429 se exceder $max em $windowSeconds. */
    public function hit(string $key, int $max, int $windowSeconds): void
    {
        $file = $this->dir . '/' . hash('sha256', $key) . '.json';
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return; // falha de disco não deve derrubar o login
        }
        try {
            flock($fp, LOCK_EX);
            $state = json_decode((string) stream_get_contents($fp), true) ?: [];
            $now = time();
            if (($state['reset'] ?? 0) <= $now) {
                $state = ['count' => 0, 'reset' => $now + $windowSeconds];
            }
            $state['count']++;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($state));
            if ($state['count'] > $max) {
                $wait = max(1, $state['reset'] - $now);
                throw new ApiException(
                    "Muitas tentativas. Tente novamente em {$wait} segundos.",
                    429,
                    'too_many_requests'
                );
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function clear(string $key): void
    {
        @unlink($this->dir . '/' . hash('sha256', $key) . '.json');
    }
}
