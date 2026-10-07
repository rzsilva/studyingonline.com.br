<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

final class Titulo
{
    public function __construct(
        public readonly int $id,
        public readonly int $instituicaoId,
        public readonly float $valor,
        public readonly string $vencimento,      // Y-m-d
        public readonly int $categoriaId,        // 1 mensalidade, 2 matrícula, 3 rematrícula
        public readonly string $descricao,
        public readonly array $pagador,          // nome, email, cpf, cep, uf, cidade, bairro, rua, numero, dataNascimento
        public readonly ?string $numDocumento = null,
        public readonly ?string $tokenBoleto = null,  // TOKEN_BOLETOCLOUD
        public readonly ?string $tokenCartao = null,  // TOKEN_CC
    ) {
    }

    public static function fromRow(array $r): self
    {
        return new self(
            (int) $r['ID'], (int) $r['INSTITUICAO_ID'], round((float) $r['VALOR'], 2), substr((string) $r['DATA_VENCIMENTO'], 0, 10),
            (int) ($r['LISTA_CATEGORIA_CR_ID'] ?? 1), (string) ($r['DESCRICAO'] ?? "Cobrança #{$r['ID']}"),
            [
                'nome' => (string) ($r['NOME'] ?? ''), 'email' => (string) ($r['EMAIL'] ?? ''), 'cpf' => (string) ($r['CPF'] ?? ''),
                'cep' => (string) ($r['CEP'] ?? ''), 'uf' => (string) ($r['UF'] ?? ''), 'cidade' => (string) ($r['CIDADE'] ?? ''),
                'bairro' => (string) ($r['BAIRRO'] ?? ''), 'rua' => (string) ($r['RUA'] ?? ''), 'numero' => (string) ($r['NUMERO'] ?? ''),
                'dataNascimento' => $r['DATA_NASCIMENTO'] ?? null,
            ],
            $r['NUM_DOCUMENTO'] ?: null, $r['TOKEN_BOLETOCLOUD'] ?: null, $r['TOKEN_CC'] ?: null,
        );
    }
}
