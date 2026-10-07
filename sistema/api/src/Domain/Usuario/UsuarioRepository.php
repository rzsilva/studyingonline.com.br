<?php

declare(strict_types=1);

namespace App\Domain\Usuario;

use App\Support\Connection;

final class UsuarioRepository
{
    /** Colunas seguras para devolver ao cliente (nunca SENHA/SENHA_HASH). */
    private const PUBLIC_COLUMNS = 'u.ID, u.INSTITUICAO_ID, u.LISTA_PERFIL_ID, u.LISTA_TURMA_ID, u.LISTA_UNIDADE_ID,
        u.MATRICULA, u.EMAIL, u.NOME, u.URL, u.INATIVO, u.MASTER, u.ONLINE, u.MSG_NO_VIEW,
        u.TELEFONE, u.CELULAR, u.DATA_NASCIMENTO, u.CPF, u.CEP, u.RUA, u.NUMERO, u.BAIRRO, u.CIDADE, u.UF';

    public function __construct(private readonly Connection $db)
    {
    }

    /** Usuários com este e-mail (legado não garante unicidade entre instituições). */
    public function findCredentialsByEmail(string $email): array
    {
        $st = $this->db->prepare(
            'SELECT u.ID, u.INSTITUICAO_ID, u.LISTA_PERFIL_ID, u.MASTER, u.INATIVO, u.SENHA, u.SENHA_HASH,
                    i.ATIVO AS INSTITUICAO_ATIVA
               FROM USUARIO u JOIN INSTITUICAO i ON i.ID = u.INSTITUICAO_ID
              WHERE u.EMAIL = ?'
        );
        $st->execute([$email]);
        return $st->fetchAll();
    }

    public function findCredentialsById(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT u.ID, u.INSTITUICAO_ID, u.LISTA_PERFIL_ID, u.MASTER, u.INATIVO, u.SENHA, u.SENHA_HASH,
                    i.ATIVO AS INSTITUICAO_ATIVA
               FROM USUARIO u JOIN INSTITUICAO i ON i.ID = u.INSTITUICAO_ID
              WHERE u.ID = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findById(int $id, int $instituicaoId): ?array
    {
        $st = $this->db->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ', p.VALOR AS PERFIL
               FROM USUARIO u LEFT JOIN LISTA_PERFIL p ON p.ID = u.LISTA_PERFIL_ID
              WHERE u.ID = ? AND u.INSTITUICAO_ID = ?'
        );
        $st->execute([$id, $instituicaoId]);
        return $st->fetch() ?: null;
    }

    public function savePasswordHash(int $id, string $hash, bool $clearPlaintext): void
    {
        $sql = $clearPlaintext
            ? 'UPDATE USUARIO SET SENHA_HASH = ?, SENHA = NULL, DATA_UPDATE = NOW() WHERE ID = ?'
            : 'UPDATE USUARIO SET SENHA_HASH = ?, DATA_UPDATE = NOW() WHERE ID = ?';
        $this->db->prepare($sql)->execute([$hash, $id]);
    }

    /**
     * Troca de senha. Enquanto o sistema legado coexistir, a coluna SENHA também
     * é atualizada para o login antigo continuar funcionando.
     */
    public function updatePassword(int $id, string $plain, string $hash, bool $clearPlaintext): void
    {
        if ($clearPlaintext) {
            $this->savePasswordHash($id, $hash, true);
            return;
        }
        $this->db->prepare('UPDATE USUARIO SET SENHA_HASH = ?, SENHA = ?, DATA_UPDATE = NOW() WHERE ID = ?')
            ->execute([$hash, $plain, $id]);
    }

    public function setOnline(int $id, bool $online): void
    {
        $this->db->prepare('UPDATE USUARIO SET ONLINE = ? WHERE ID = ?')->execute([(int) $online, $id]);
    }

    /**
     * Pendência financeira: título VENCIDO, não recebido (2) e não cancelado (4).
     * O legado considerava qualquer título em aberto, inclusive parcelas futuras,
     * o que bloqueava alunos em dia; aqui só contam os vencidos.
     */
    public function hasPendenciaFinanceira(int $id, int $instituicaoId): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM CONTAS_RECEBER
              WHERE USUARIO_ID = ? AND INSTITUICAO_ID = ?
                AND COALESCE(LISTA_SITUACAO_CR_ID, 0) NOT IN (2, 4) AND DATA_VENCIMENTO < CURDATE() LIMIT 1'
        );
        $st->execute([$id, $instituicaoId]);
        return (bool) $st->fetchColumn();
    }
}
