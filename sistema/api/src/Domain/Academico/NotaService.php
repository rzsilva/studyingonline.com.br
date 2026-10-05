<?php

declare(strict_types=1);

namespace App\Domain\Academico;

use App\Domain\Auth\AuthUser;
use App\Domain\Trilha\PainelService;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;

/** Lançamento de notas (antes NotaController + telas Notas por Curso/Disciplina/Aluno). */
final class NotaService
{
    public function __construct(private readonly Connection $db, private readonly Audit $audit)
    {
    }

    /** Alunos matriculados no curso do módulo, com a nota de cada um. */
    public function porModulo(AuthUser $user, int $moduloId): array
    {
        $mod = $this->modulo($user, $moduloId);
        $rows = $this->db->run(
            'SELECT u.ID AS USUARIO_ID, u.NOME, u.MATRICULA, n.ID AS NOTA_ID, n.NOTA1, n.NOTA_RECUPERACAO, n.FALTAS,
                    COALESCE(n.LISTA_STATUS_NOTA_ID, 1) AS STATUS_ID
               FROM USUARIO_CURSO uc
               JOIN USUARIO u ON u.ID = uc.USUARIO_ID
               LEFT JOIN NOTA n ON n.USUARIO_ID = u.ID AND n.DISCIPLINA_ID = ?
              WHERE uc.CURSO_ID = ? AND uc.INSTITUICAO_ID = ?
              ORDER BY u.NOME',
            [$moduloId, $mod['CURSO_ID'], $user->instituicaoId]
        )->fetchAll();

        return [
            'modulo' => ['id' => $moduloId, 'nome' => $mod['DISCIPLINA'], 'curso' => $mod['CURSO'],
                'media' => $mod['MEDIA'] !== null ? (float) $mod['MEDIA'] : null],
            'alunos' => array_map(static fn ($r) => [
                'usuarioId' => (int) $r['USUARIO_ID'],
                'nome' => $r['NOME'],
                'matricula' => $r['MATRICULA'],
                'nota1' => $r['NOTA1'] !== null ? (float) $r['NOTA1'] : null,
                'notaRecuperacao' => $r['NOTA_RECUPERACAO'] !== null ? (float) $r['NOTA_RECUPERACAO'] : null,
                'faltas' => $r['FALTAS'] !== null ? (int) $r['FALTAS'] : null,
                'statusId' => (int) $r['STATUS_ID'],
            ], $rows),
        ];
    }

    /**
     * Grava notas em lote. Status calculado pela média do curso quando não informado:
     * sem nota = cursando; maior nota (normal/recuperação) >= média = aprovado; senão reprovado.
     *
     * @param list<array{usuarioId:int, nota1?:mixed, notaRecuperacao?:mixed, faltas?:mixed, statusId?:mixed}> $itens
     */
    public function salvarModulo(AuthUser $user, int $moduloId, array $itens): int
    {
        $mod = $this->modulo($user, $moduloId);
        $matriculados = array_flip(array_map('intval', $this->db->run(
            'SELECT USUARIO_ID FROM USUARIO_CURSO WHERE CURSO_ID = ? AND INSTITUICAO_ID = ?',
            [$mod['CURSO_ID'], $user->instituicaoId]
        )->fetchAll(\PDO::FETCH_COLUMN)));
        $media = $mod['MEDIA'] !== null ? (float) $mod['MEDIA'] : null;

        $errors = [];
        $validos = [];
        foreach ($itens as $i => $item) {
            $uid = (int) ($item['usuarioId'] ?? 0);
            if (!isset($matriculados[$uid])) {
                $errors["notas.{$i}"] = 'Aluno não matriculado neste curso.';
                continue;
            }
            try {
                $n1 = self::num($item['nota1'] ?? null);
                $nr = self::num($item['notaRecuperacao'] ?? null);
                $faltas = isset($item['faltas']) && $item['faltas'] !== '' ? max(0, (int) $item['faltas']) : null;
            } catch (\InvalidArgumentException $e) {
                $errors["notas.{$i}"] = $e->getMessage();
                continue;
            }
            $status = isset($item['statusId']) && in_array((int) $item['statusId'], [1, 2, 3], true)
                ? (int) $item['statusId']
                : self::statusAutomatico($n1, $nr, $media);
            $validos[] = [$uid, $n1, $nr, $faltas, $status];
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }

        $this->db->beginTransaction();
        try {
            foreach ($validos as [$uid, $n1, $nr, $faltas, $status]) {
                $id = $this->db->run('SELECT ID FROM NOTA WHERE USUARIO_ID = ? AND DISCIPLINA_ID = ? AND INSTITUICAO_ID = ?',
                    [$uid, $moduloId, $user->instituicaoId])->fetchColumn();
                if ($id) {
                    $this->db->run('UPDATE NOTA SET NOTA1 = ?, NOTA_RECUPERACAO = ?, FALTAS = ?, LISTA_STATUS_NOTA_ID = ? WHERE ID = ?',
                        [$n1, $nr, $faltas, $status, (int) $id]);
                } else {
                    $this->db->run(
                        'INSERT INTO NOTA (INSTITUICAO_ID, DISCIPLINA_ID, USUARIO_ID, NOTA1, NOTA_RECUPERACAO, FALTAS, LISTA_STATUS_NOTA_ID, DATA_CADASTRO)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                        [$user->instituicaoId, $moduloId, $uid, $n1, $nr, $faltas, $status]
                    );
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->audit->log($user, 'lancar_notas', 'DISCIPLINA', $moduloId, null, ['alunos' => count($validos)]);
        return count($validos);
    }

    /** Boletim do aluno em um curso (aluno só o próprio). */
    public function boletim(AuthUser $user, int $usuarioId, int $cursoId): array
    {
        if (!$user->canAccessUser($usuarioId)) {
            throw ApiException::forbidden();
        }
        $curso = $this->db->run('SELECT NOME, MEDIA FROM CURSO WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$cursoId, $user->instituicaoId])->fetch();
        $aluno = $this->db->run('SELECT NOME, MATRICULA FROM USUARIO WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$usuarioId, $user->instituicaoId])->fetch();
        if (!$curso || !$aluno) {
            throw ApiException::notFound();
        }
        $rows = $this->db->run(
            'SELECT d.ID, d.PERIODO, ld.VALOR AS DISCIPLINA, n.NOTA1, n.NOTA_RECUPERACAO, n.FALTAS, n.LISTA_STATUS_NOTA_ID
               FROM DISCIPLINA d
               LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = d.LISTA_DISCIPLINA_ID
               LEFT JOIN NOTA n ON n.DISCIPLINA_ID = d.ID AND n.USUARIO_ID = ?
              WHERE d.CURSO_ID = ? AND d.INSTITUICAO_ID = ?
              ORDER BY COALESCE(d.ORDEM, 2147483647), d.DATA_INICIO, d.ID',
            [$usuarioId, $cursoId, $user->instituicaoId]
        )->fetchAll();

        return [
            'aluno' => ['id' => $usuarioId, 'nome' => $aluno['NOME'], 'matricula' => $aluno['MATRICULA']],
            'curso' => ['id' => $cursoId, 'nome' => $curso['NOME'], 'media' => $curso['MEDIA'] !== null ? (float) $curso['MEDIA'] : null],
            'disciplinas' => array_map(static fn ($r) => [
                'moduloId' => (int) $r['ID'],
                'periodo' => $r['PERIODO'],
                'disciplina' => $r['DISCIPLINA'],
                'nota' => $r['NOTA1'] !== null ? (float) $r['NOTA1'] : null,
                'notaRecuperacao' => $r['NOTA_RECUPERACAO'] !== null ? (float) $r['NOTA_RECUPERACAO'] : null,
                'faltas' => $r['FALTAS'] !== null ? (int) $r['FALTAS'] : null,
                'resultado' => PainelService::statusNome((int) ($r['LISTA_STATUS_NOTA_ID'] ?? 1)),
            ], $rows),
        ];
    }

    public static function statusAutomatico(?float $n1, ?float $nr, ?float $media): int
    {
        if ($n1 === null && $nr === null) {
            return 1;
        }
        return max($n1 ?? 0, $nr ?? 0) >= ($media ?? 0) ? 2 : 3;
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = is_string($v) ? str_replace(',', '.', $v) : $v;
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > 1000) {
            throw new \InvalidArgumentException('Nota inválida.');
        }
        return round((float) $v, 2);
    }

    private function modulo(AuthUser $user, int $moduloId): array
    {
        $mod = $this->db->run(
            'SELECT d.ID, d.CURSO_ID, ld.VALOR AS DISCIPLINA, c.NOME AS CURSO, c.MEDIA
               FROM DISCIPLINA d JOIN CURSO c ON c.ID = d.CURSO_ID
               LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = d.LISTA_DISCIPLINA_ID
              WHERE d.ID = ? AND d.INSTITUICAO_ID = ?',
            [$moduloId, $user->instituicaoId]
        )->fetch();
        if (!$mod) {
            throw ApiException::notFound('Módulo não encontrado.');
        }
        return $mod;
    }
}
