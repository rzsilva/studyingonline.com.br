<?php

declare(strict_types=1);

namespace App\Domain\Instituicao;

use App\Support\Connection;

final class InstituicaoRepository
{
    /** Dados públicos para tela de login / tema (white-label). */
    private const PUBLIC_COLUMNS = 'ID, FANTASIA, TITULO, LOGO, COR_PRIMARIA, URL, CELULAR, EMAIL, ATIVO';

    public function __construct(private readonly Connection $db)
    {
    }

    public function findPublicByUrl(string $url): ?array
    {
        $st = $this->db->prepare('SELECT ' . self::PUBLIC_COLUMNS . ' FROM INSTITUICAO WHERE URL = ? LIMIT 1');
        $st->execute([$url]);
        return $st->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ', DATA_VENCIMENTO, COBRAR_BOLETOS FROM INSTITUICAO WHERE ID = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
}
