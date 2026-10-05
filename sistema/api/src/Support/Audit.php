<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Auth\AuthUser;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/** Trilha de auditoria (tabela AUDITORIA). Falha ao auditar não interrompe a operação. */
final class Audit
{
    public function __construct(private readonly Connection $db, private readonly LoggerInterface $logger)
    {
    }

    public function log(AuthUser $user, string $acao, string $entidade, ?int $id, ?Request $request = null, ?array $dados = null): void
    {
        try {
            $this->db->run(
                'INSERT INTO AUDITORIA (INSTITUICAO_ID, USUARIO_ID, ACAO, ENTIDADE, ENTIDADE_ID, DADOS, IP, CRIADO_EM)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $user->instituicaoId,
                    $user->id,
                    $acao,
                    $entidade,
                    $id,
                    $dados !== null ? json_encode($dados, JSON_UNESCAPED_UNICODE) : null,
                    $request?->getServerParams()['REMOTE_ADDR'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Falha ao gravar auditoria: ' . $e->getMessage());
        }
    }
}
