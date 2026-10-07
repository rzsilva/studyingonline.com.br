<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cifra credenciais guardadas no banco (ex.: CONTA_BANCARIA.GATEWAY_TOKEN_PROD) com AES-256-GCM.
 * Formato: "enc:v1:" + base64(iv[12] . tag[16] . cifrado). Valores sem o prefixo são lidos como texto
 * puro — necessário enquanto o sistema legado (que lê o token em texto) estiver no ar.
 * Só passa a cifrar na gravação quando SECRETS_ENCRYPT=true (depois da virada).
 */
final class Segredo
{
    private const PREFIXO = 'enc:v1:';

    public function __construct(private readonly string $chave, private readonly bool $cifrarAoGravar)
    {
    }

    public static function chaveValida(string $hex): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/i', $hex);
    }

    public function cifrado(?string $valor): bool
    {
        return $valor !== null && str_starts_with($valor, self::PREFIXO);
    }

    /** Valor a gravar no banco. */
    public function paraGravar(string $valor): string
    {
        return $this->cifrarAoGravar ? $this->cifrar($valor) : $valor;
    }

    public function cifrar(string $valor): string
    {
        if (!self::chaveValida($this->chave)) {
            throw new \RuntimeException('SECRETS_KEY ausente ou inválida (64 caracteres hexadecimais).');
        }
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($valor, 'aes-256-gcm', hex2bin($this->chave), OPENSSL_RAW_DATA, $iv, $tag);
        if ($c === false) {
            throw new \RuntimeException('Falha ao cifrar.');
        }
        return self::PREFIXO . base64_encode($iv . $tag . $c);
    }

    /** Valor em claro para uso (aceita texto puro do legado). */
    public function abrir(?string $valor): ?string
    {
        if ($valor === null || !$this->cifrado($valor)) {
            return $valor;
        }
        if (!self::chaveValida($this->chave)) {
            throw new \RuntimeException('Credencial cifrada, mas SECRETS_KEY não está configurada.');
        }
        $raw = base64_decode(substr($valor, strlen(self::PREFIXO)), true);
        $p = $raw === false || strlen($raw) < 29 ? false
            : openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hex2bin($this->chave), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($p === false) {
            throw new \RuntimeException('Credencial cifrada inválida ou SECRETS_KEY diferente da usada na cifragem.');
        }
        return $p;
    }
}
