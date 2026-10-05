<?php

declare(strict_types=1);

namespace App\Domain\Comunidade;

use App\Domain\Auth\AuthUser;
use App\Domain\Trilha\ArquivoUrl;
use App\Domain\Usuario\UsuarioRepository;
use App\Support\ApiException;
use App\Support\Connection;

/**
 * Painel presencial (antes PainelPresencialController + Views/Painel/Presencial.cshtml):
 * cursos com turma presencial (CURSO.TIPO_TURMA = 2), aulas por módulo, material e colegas.
 * Diferente do legado, o aluno só abre cursos em que está matriculado e a lista de colegas
 * traz apenas nome e foto (antes vinha o cadastro completo).
 */
final class PresencialService
{
    public const TURMA_PRESENCIAL = 2;

    public function __construct(private readonly Connection $db, private readonly UsuarioRepository $usuarios)
    {
    }

    public function cursos(AuthUser $user): array
    {
        if ($user->isAluno()) {
            $this->assertLiberado($user);
            $rows = $this->db->run(
                'SELECT c.ID, c.NOME, c.SUBTITULO, c.FOTO FROM USUARIO_CURSO uc JOIN CURSO c ON c.ID = uc.CURSO_ID
                  WHERE uc.USUARIO_ID = ? AND uc.INSTITUICAO_ID = ? AND c.TIPO_TURMA = ? AND c.ATIVO = 1 ORDER BY c.NOME',
                [$user->id, $user->instituicaoId, self::TURMA_PRESENCIAL]
            )->fetchAll();
        } else {
            $rows = $this->db->run(
                'SELECT ID, NOME, SUBTITULO, FOTO FROM CURSO WHERE INSTITUICAO_ID = ? AND TIPO_TURMA = ? AND ATIVO = 1 ORDER BY NOME',
                [$user->instituicaoId, self::TURMA_PRESENCIAL]
            )->fetchAll();
        }
        return array_map(static fn ($r) => ['id' => (int) $r['ID'], 'nome' => $r['NOME'], 'subtitulo' => $r['SUBTITULO'], 'foto' => $r['FOTO']], $rows);
    }

    public function curso(AuthUser $user, int $cursoId): array
    {
        $curso = $this->db->run('SELECT ID, NOME, MEDIA FROM CURSO WHERE ID = ? AND INSTITUICAO_ID = ? AND TIPO_TURMA = ?',
            [$cursoId, $user->instituicaoId, self::TURMA_PRESENCIAL])->fetch();
        if (!$curso) {
            throw ApiException::notFound('Curso não encontrado.');
        }
        if ($user->isAluno()) {
            $this->assertLiberado($user);
            $mat = $this->db->run('SELECT 1 FROM USUARIO_CURSO WHERE USUARIO_ID = ? AND CURSO_ID = ?', [$user->id, $cursoId])->fetchColumn();
            if (!$mat) {
                throw ApiException::notFound('Curso não encontrado.');
            }
        }

        $modulos = $this->db->run(
            'SELECT d.ID, d.PERIODO, d.LISTA_DISCIPLINA_ID, ld.VALOR AS DISCIPLINA, lp.PROFESSOR
               FROM DISCIPLINA d LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = d.LISTA_DISCIPLINA_ID
               LEFT JOIN LISTA_PROFESSOR lp ON lp.ID = d.LISTA_PROFESSOR_ID
              WHERE d.CURSO_ID = ? AND d.INSTITUICAO_ID = ?
              ORDER BY COALESCE(d.ORDEM, 2147483647), d.DATA_INICIO, d.ID',
            [$cursoId, $user->instituicaoId]
        )->fetchAll();

        $aulas = [];
        $arquivos = [];
        if ($modulos) {
            $ids = array_map(static fn ($m) => (int) $m['ID'], $modulos);
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->run(
                "SELECT ID, DISCIPLINA_ID, TITULO, DESCRICAO, URL, DATA_AULA, INICIO, TERMINO FROM AULA
                  WHERE DISCIPLINA_ID IN ({$in}) ORDER BY DATA_AULA, INICIO",
                $ids
            )->fetchAll() as $a) {
                $aulas[(int) $a['DISCIPLINA_ID']][] = [
                    'id' => (int) $a['ID'], 'titulo' => $a['TITULO'], 'descricao' => $a['DESCRICAO'], 'url' => $a['URL'],
                    'data' => $a['DATA_AULA'], 'inicio' => $a['INICIO'], 'termino' => $a['TERMINO'],
                ];
            }
            $ld = array_values(array_unique(array_filter(array_map(static fn ($m) => (int) $m['LISTA_DISCIPLINA_ID'], $modulos))));
            if ($ld) {
                $in2 = implode(',', array_fill(0, count($ld), '?'));
                foreach ($this->db->run(
                    "SELECT ID, LISTA_DISCIPLINA_ID, TITULO, URL FROM ARQUIVO WHERE INSTITUICAO_ID = ? AND LISTA_DISCIPLINA_ID IN ({$in2}) ORDER BY TITULO",
                    [$user->instituicaoId, ...$ld]
                )->fetchAll() as $f) {
                    $arquivos[(int) $f['LISTA_DISCIPLINA_ID']][] = ['id' => (int) $f['ID'], 'titulo' => $f['TITULO'], 'url' => ArquivoUrl::publica($f['URL'], (int) $f['ID'])];
                }
            }
        }

        $colegas = $this->db->run(
            'SELECT u.ID, u.NOME, u.URL FROM USUARIO_CURSO uc JOIN USUARIO u ON u.ID = uc.USUARIO_ID
              WHERE uc.CURSO_ID = ? AND uc.INSTITUICAO_ID = ? AND u.INATIVO = 0 ORDER BY u.NOME',
            [$cursoId, $user->instituicaoId]
        )->fetchAll();

        return [
            'curso' => ['id' => (int) $curso['ID'], 'nome' => $curso['NOME']],
            'modulos' => array_map(static fn ($m) => [
                'id' => (int) $m['ID'],
                'nome' => $m['DISCIPLINA'],
                'periodo' => $m['PERIODO'],
                'professor' => $m['PROFESSOR'],
                'aulas' => $aulas[(int) $m['ID']] ?? [],
                'arquivos' => $arquivos[(int) $m['LISTA_DISCIPLINA_ID']] ?? [],
            ], $modulos),
            'colegas' => array_map(static fn ($c) => ['id' => (int) $c['ID'], 'nome' => $c['NOME'], 'foto' => $c['URL']], $colegas),
        ];
    }

    /** Extrato financeiro do próprio aluno (sempre visível, inclusive com pendência). */
    public function financeiro(AuthUser $user): array
    {
        $rows = $this->db->run(
            'SELECT cr.ID, cr.DATA_VENCIMENTO, cr.DATA_PAGAMENTO, cr.VALOR, cr.NUM_DOCUMENTO, cr.OBSERVACAO,
                    cr.LISTA_SITUACAO_CR_ID, s.VALOR AS SITUACAO, cat.VALOR AS CATEGORIA
               FROM CONTAS_RECEBER cr
               LEFT JOIN LISTA_SITUACAO_CR s ON s.ID = cr.LISTA_SITUACAO_CR_ID
               LEFT JOIN LISTA_CATEGORIA_CR cat ON cat.ID = cr.LISTA_CATEGORIA_CR_ID
              WHERE cr.USUARIO_ID = ? AND cr.INSTITUICAO_ID = ?
              ORDER BY cr.DATA_VENCIMENTO DESC',
            [$user->id, $user->instituicaoId]
        )->fetchAll();
        $hoje = date('Y-m-d');
        return array_map(static function ($r) use ($hoje) {
            $pago = (int) $r['LISTA_SITUACAO_CR_ID'] === 2;
            return [
                'id' => (int) $r['ID'],
                'vencimento' => $r['DATA_VENCIMENTO'],
                'pagamento' => $r['DATA_PAGAMENTO'],
                'valor' => (float) $r['VALOR'],
                'documento' => $r['NUM_DOCUMENTO'],
                'descricao' => $r['CATEGORIA'] ?? $r['OBSERVACAO'],
                'situacao' => $r['SITUACAO'] ?? ($pago ? 'PAGO' : 'EM ABERTO'),
                'pago' => $pago,
                'vencido' => !$pago && $r['DATA_VENCIMENTO'] !== null && substr($r['DATA_VENCIMENTO'], 0, 10) < $hoje,
            ];
        }, $rows);
    }

    private function assertLiberado(AuthUser $user): void
    {
        $u = $this->usuarios->findById($user->id, $user->instituicaoId);
        if (!$u || (bool) $u['INATIVO'] || $this->usuarios->hasPendenciaFinanceira($user->id, $user->instituicaoId)) {
            throw ApiException::forbidden('Acesso ao painel suspenso. Procure a secretaria.');
        }
    }
}
