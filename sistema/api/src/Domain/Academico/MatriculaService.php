<?php

declare(strict_types=1);

namespace App\Domain\Academico;

use App\Domain\Auth\AuthUser;
use App\Domain\Auth\Perfil;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;

/** "Montar Turma": alunos de um curso (tabela USUARIO_CURSO, chave INSTITUICAO+USUARIO+CURSO). */
final class MatriculaService
{
    public function __construct(private readonly Connection $db, private readonly Audit $audit)
    {
    }

    public function alunos(AuthUser $user, int $cursoId): array
    {
        $this->curso($user, $cursoId);
        return array_map(self::mapAluno(...), $this->db->run(
            'SELECT u.ID, u.NOME, u.EMAIL, u.MATRICULA, u.INATIVO, uc.DATA_CADASTRO
               FROM USUARIO_CURSO uc JOIN USUARIO u ON u.ID = uc.USUARIO_ID
              WHERE uc.CURSO_ID = ? AND uc.INSTITUICAO_ID = ? ORDER BY u.NOME',
            [$cursoId, $user->instituicaoId]
        )->fetchAll());
    }

    public function disponiveis(AuthUser $user, int $cursoId, string $q): array
    {
        $this->curso($user, $cursoId);
        $params = [$user->instituicaoId, Perfil::Aluno->value, $cursoId];
        $filtro = '';
        if ($q !== '') {
            $filtro = ' AND (u.NOME LIKE ? OR u.EMAIL LIKE ? OR u.MATRICULA LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        return array_map(self::mapAluno(...), $this->db->run(
            "SELECT u.ID, u.NOME, u.EMAIL, u.MATRICULA, u.INATIVO, NULL AS DATA_CADASTRO
               FROM USUARIO u
              WHERE u.INSTITUICAO_ID = ? AND u.LISTA_PERFIL_ID = ?
                AND NOT EXISTS (SELECT 1 FROM USUARIO_CURSO uc WHERE uc.USUARIO_ID = u.ID AND uc.CURSO_ID = ?)
                {$filtro}
              ORDER BY u.NOME LIMIT 50",
            $params
        )->fetchAll());
    }

    /** @param int[] $usuarioIds */
    public function adicionar(AuthUser $user, int $cursoId, array $usuarioIds): int
    {
        $curso = $this->curso($user, $cursoId);
        $ids = array_values(array_unique(array_filter(array_map('intval', $usuarioIds))));
        if (!$ids) {
            throw ApiException::validation(['usuarioIds' => 'Selecione ao menos um aluno.']);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $validos = array_map('intval', $this->db->run(
            "SELECT ID FROM USUARIO WHERE INSTITUICAO_ID = ? AND LISTA_PERFIL_ID = ? AND ID IN ({$in})",
            [$user->instituicaoId, Perfil::Aluno->value, ...$ids]
        )->fetchAll(\PDO::FETCH_COLUMN));
        if (count($validos) !== count($ids)) {
            throw ApiException::validation(['usuarioIds' => 'Há usuários inválidos na seleção.']);
        }

        if ($curso['LIMITE_ALUNOS_TURMA']) {
            $atual = (int) $this->db->run('SELECT COUNT(*) FROM USUARIO_CURSO WHERE CURSO_ID = ?', [$cursoId])->fetchColumn();
            if ($atual + count($validos) > (int) $curso['LIMITE_ALUNOS_TURMA']) {
                throw new ApiException("Limite de {$curso['LIMITE_ALUNOS_TURMA']} alunos na turma excedido.", 422, 'limit');
            }
        }

        $n = 0;
        foreach ($validos as $uid) {
            $n += $this->db->run(
                'INSERT IGNORE INTO USUARIO_CURSO (INSTITUICAO_ID, USUARIO_ID, CURSO_ID, DATA_CADASTRO) VALUES (?, ?, ?, NOW())',
                [$user->instituicaoId, $uid, $cursoId]
            )->rowCount();
        }
        $this->audit->log($user, 'matricular', 'CURSO', $cursoId, null, ['usuarios' => $validos]);
        return $n;
    }

    public function remover(AuthUser $user, int $cursoId, int $usuarioId): void
    {
        $this->curso($user, $cursoId);
        $n = $this->db->run('DELETE FROM USUARIO_CURSO WHERE CURSO_ID = ? AND USUARIO_ID = ? AND INSTITUICAO_ID = ?',
            [$cursoId, $usuarioId, $user->instituicaoId])->rowCount();
        if ($n === 0) {
            throw ApiException::notFound('Aluno não está neste curso.');
        }
        $this->audit->log($user, 'desmatricular', 'CURSO', $cursoId, null, ['usuario' => $usuarioId]);
    }

    /** Troca de turma: move a matrícula preservando a inscrição (como TrocarAlunoCurso no legado). */
    public function trocar(AuthUser $user, int $cursoId, int $usuarioId, int $destinoId): void
    {
        $this->curso($user, $cursoId);
        $this->curso($user, $destinoId);
        if ($cursoId === $destinoId) {
            throw ApiException::validation(['cursoDestinoId' => 'Escolha outro curso.']);
        }
        $mat = $this->db->run('SELECT INSCRICAO_ID FROM USUARIO_CURSO WHERE CURSO_ID = ? AND USUARIO_ID = ? AND INSTITUICAO_ID = ?',
            [$cursoId, $usuarioId, $user->instituicaoId])->fetch();
        if (!$mat) {
            throw ApiException::notFound('Aluno não está neste curso.');
        }
        $this->db->beginTransaction();
        try {
            $this->db->run(
                'INSERT IGNORE INTO USUARIO_CURSO (INSTITUICAO_ID, USUARIO_ID, CURSO_ID, INSCRICAO_ID, DATA_CADASTRO) VALUES (?, ?, ?, ?, NOW())',
                [$user->instituicaoId, $usuarioId, $destinoId, $mat['INSCRICAO_ID']]
            );
            $this->db->run('DELETE FROM USUARIO_CURSO WHERE CURSO_ID = ? AND USUARIO_ID = ? AND INSTITUICAO_ID = ?',
                [$cursoId, $usuarioId, $user->instituicaoId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->audit->log($user, 'trocar_turma', 'CURSO', $cursoId, null, ['usuario' => $usuarioId, 'destino' => $destinoId]);
    }

    private function curso(AuthUser $user, int $id): array
    {
        $c = $this->db->run('SELECT ID, LIMITE_ALUNOS_TURMA FROM CURSO WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$id, $user->instituicaoId])->fetch();
        if (!$c) {
            throw ApiException::notFound('Curso não encontrado.');
        }
        return $c;
    }

    private static function mapAluno(array $r): array
    {
        return [
            'id' => (int) $r['ID'],
            'nome' => $r['NOME'],
            'email' => $r['EMAIL'],
            'matricula' => $r['MATRICULA'],
            'inativo' => (bool) $r['INATIVO'],
            'dataMatricula' => $r['DATA_CADASTRO'],
        ];
    }
}
