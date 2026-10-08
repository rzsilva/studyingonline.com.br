<?php

declare(strict_types=1);

namespace App\Domain\Trilha;

use App\Domain\Auth\AuthUser;
use App\Domain\Usuario\UsuarioRepository;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;

/**
 * Painel EAD do aluno (antes PainelOnlineController + Views/Painel/OnLine.cshtml).
 * Diferenças de segurança em relação ao legado:
 *  - aluno só acessa curso em que está matriculado (USUARIO_CURSO)
 *  - aluno inativo/com pendência não acessa (antes só o menu era escondido)
 *  - gabarito nunca é enviado; nota é calculada aqui; prova só pode ser enviada uma vez
 */
final class PainelService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TrilhaService $trilha,
        private readonly CorrecaoProva $correcao,
        private readonly UsuarioRepository $usuarios,
        private readonly Audit $audit,
    ) {
    }

    public function meusCursos(AuthUser $user): array
    {
        if ($user->isAluno()) {
            $this->assertAlunoLiberado($user);
            $rows = $this->db->run(
                'SELECT c.ID, c.NOME, c.SUBTITULO, c.FOTO, c.MEDIA, uc.DATA_CADASTRO AS DATA_MATRICULA,
                        (SELECT COUNT(*) FROM DISCIPLINA d WHERE d.CURSO_ID = c.ID) AS TOTAL_MODULOS
                   FROM USUARIO_CURSO uc JOIN CURSO c ON c.ID = uc.CURSO_ID
                  WHERE uc.USUARIO_ID = ? AND uc.INSTITUICAO_ID = ?
                  ORDER BY uc.DATA_CADASTRO DESC',
                [$user->id, $user->instituicaoId]
            )->fetchAll();
        } else {
            $rows = $this->db->run(
                'SELECT c.ID, c.NOME, c.SUBTITULO, c.FOTO, c.MEDIA, NULL AS DATA_MATRICULA,
                        (SELECT COUNT(*) FROM DISCIPLINA d WHERE d.CURSO_ID = c.ID) AS TOTAL_MODULOS
                   FROM CURSO c WHERE c.INSTITUICAO_ID = ? AND c.ATIVO = 1 ORDER BY c.NOME',
                [$user->instituicaoId]
            )->fetchAll();
        }
        return array_map(static fn ($r) => [
            'id' => (int) $r['ID'],
            'nome' => $r['NOME'],
            'subtitulo' => $r['SUBTITULO'],
            'foto' => $r['FOTO'],
            'media' => $r['MEDIA'] !== null ? (float) $r['MEDIA'] : null,
            'dataMatricula' => $r['DATA_MATRICULA'],
            'totalModulos' => (int) $r['TOTAL_MODULOS'],
        ], $rows);
    }

    /** Trilha completa de um curso com status de liberação por módulo e vídeo. */
    public function trilha(AuthUser $user, int $cursoId): array
    {
        $ctx = $this->contexto($user, $cursoId);
        $status = $ctx['status'];
        $aluno = $user->isAluno();

        // Como no legado: módulo liberado sem NOTA ganha uma NOTA "cursando" (marca de liberação)
        if ($aluno) {
            foreach ($ctx['modulos'] as $m) {
                $id = (int) $m['ID'];
                if ($status[$id]->liberado && !array_key_exists($id, $ctx['notas'])) {
                    $this->db->run(
                        'INSERT INTO NOTA (INSTITUICAO_ID, DISCIPLINA_ID, USUARIO_ID, LISTA_STATUS_NOTA_ID, DATA_CADASTRO)
                         VALUES (?, ?, ?, 1, NOW())',
                        [$user->instituicaoId, $id, $user->id]
                    );
                    $ctx['notas'][$id] = 1;
                    $ctx['notasRows'][$id] = ['NOTA1' => null, 'NOTA_RECUPERACAO' => null, 'FALTAS' => null, 'LISTA_STATUS_NOTA_ID' => 1];
                }
            }
        }

        $arquivos = $this->arquivosPorDisciplina($user, array_column($ctx['modulos'], 'LISTA_DISCIPLINA_ID'));
        $modulos = [];
        foreach ($ctx['modulos'] as $m) {
            $id = (int) $m['ID'];
            $s = $status[$id];
            $liberado = !$aluno || $s->liberado;
            $prova = $ctx['provas'][$id] ?? null;
            $nota = $ctx['notasRows'][$id] ?? null;

            $modulos[] = [
                'id' => $id,
                'nome' => $m['DISCIPLINA'],
                'professor' => $m['PROFESSOR'],
                'periodo' => $m['PERIODO'],
                'ordem' => $m['ORDEM'] !== null ? (int) $m['ORDEM'] : null,
                'liberado' => $liberado,
                'concluido' => !$aluno || $s->concluido,
                'conteudoConcluido' => !$aluno || $s->conteudoConcluido,
                'dataLiberacaoPrevista' => $aluno && !$s->liberado ? $s->dataLiberacaoPrevista?->format('Y-m-d') : null,
                'videos' => array_map(fn ($v) => [
                    'id' => (int) $v['ID'],
                    'titulo' => $v['TITULO'],
                    'descricao' => $v['DESCRICAO'],
                    // URL só é revelada para vídeo liberado
                    'url' => (!$aluno || ($s->videosLiberados[(int) $v['ID']] ?? false)) ? $v['URL'] : null,
                    'youtube' => (bool) $v['YOUTUBE'],
                    'vimeo' => (bool) $v['VIMEO'],
                    'liberado' => !$aluno || ($s->videosLiberados[(int) $v['ID']] ?? false),
                    'assistido' => isset($ctx['concluidos'][(int) $v['ID']]),
                    'tempo' => $ctx['tempos'][(int) $v['ID']] ?? null,
                ], $ctx['videos'][$id] ?? []),
                'arquivos' => $liberado ? ($arquivos[(int) $m['LISTA_DISCIPLINA_ID']] ?? []) : [],
                'prova' => $prova ? $this->provaParaAluno($user, $prova, $liberado && (!$aluno || $s->podeFazerProva())) : null,
                'nota' => $nota ? [
                    'nota' => $nota['NOTA1'] !== null ? (float) $nota['NOTA1'] : null,
                    'notaRecuperacao' => $nota['NOTA_RECUPERACAO'] !== null ? (float) $nota['NOTA_RECUPERACAO'] : null,
                    'faltas' => $nota['FALTAS'] !== null ? (int) $nota['FALTAS'] : null,
                    'status' => self::statusNome((int) $nota['LISTA_STATUS_NOTA_ID']),
                ] : null,
            ];
        }

        return [
            'curso' => ['id' => $cursoId, 'nome' => $ctx['curso']['NOME'], 'media' => $ctx['curso']['MEDIA'] !== null ? (float) $ctx['curso']['MEDIA'] : null],
            'dataMatricula' => $ctx['dataMatricula']?->format('Y-m-d'),
            'modulos' => $modulos,
        ];
    }

    /** Registra progresso do vídeo (evento "ended" marca como assistido). */
    public function progressoVideo(AuthUser $user, int $videoId, string $evento, ?string $tempo): array
    {
        $video = $this->db->run(
            'SELECT v.ID, d.CURSO_ID FROM VIDEO v JOIN DISCIPLINA d ON d.ID = v.DISCIPLINA_ID
              WHERE v.ID = ? AND v.INSTITUICAO_ID = ?',
            [$videoId, $user->instituicaoId]
        )->fetch();
        if (!$video) {
            throw ApiException::notFound('Aula não encontrada.');
        }
        if ($user->isAluno()) {
            $ctx = $this->contexto($user, (int) $video['CURSO_ID']);
            $liberado = false;
            foreach ($ctx['status'] as $s) {
                if ($s->videosLiberados[$videoId] ?? false) {
                    $liberado = true;
                }
            }
            if (!$liberado) {
                throw ApiException::forbidden('Esta aula ainda não foi liberada para você.');
            }
        }

        $tempo = $tempo !== null ? mb_substr(preg_replace('/[^0-9:.]/', '', $tempo) ?? '', 0, 20) : null;
        $vv = $this->db->run(
            'SELECT ID, STATUS FROM VIDEO_VIEW WHERE VIDEO_ID = ? AND USUARIO_ID = ? AND INSTITUICAO_ID = ?',
            [$videoId, $user->id, $user->instituicaoId]
        )->fetch();
        $concluido = $evento === 'ended' || ($vv && (int) $vv['STATUS'] === 2);

        if (!$vv) {
            $this->db->run(
                'INSERT INTO VIDEO_VIEW (INSTITUICAO_ID, USUARIO_ID, VIDEO_ID, STATUS, TEMPO, DATA_CADASTRO) VALUES (?, ?, ?, ?, ?, NOW())',
                [$user->instituicaoId, $user->id, $videoId, $concluido ? 2 : 1, $concluido ? '' : $tempo]
            );
        } elseif ($evento === 'ended') {
            $this->db->run("UPDATE VIDEO_VIEW SET STATUS = 2, TEMPO = '' WHERE ID = ?", [(int) $vv['ID']]);
        } elseif ((int) $vv['STATUS'] !== 2) {
            $this->db->run('UPDATE VIDEO_VIEW SET TEMPO = ? WHERE ID = ?', [$tempo, (int) $vv['ID']]);
        }
        return ['videoId' => $videoId, 'assistido' => $concluido];
    }

    /**
     * Recebe só as respostas (questaoId => 'A'..'E') e corrige com o gabarito do banco.
     * Recuperação: como no legado, somente perfis não-aluno (ALLOW_RECUPERACAO = perfil 1).
     */
    public function responderProva(AuthUser $user, int $provaId, array $respostas, bool $recuperacao): array
    {
        $prova = $this->db->run(
            'SELECT p.ID, p.DISCIPLINA_ID, d.CURSO_ID, c.MEDIA
               FROM PROVA p JOIN DISCIPLINA d ON d.ID = p.DISCIPLINA_ID JOIN CURSO c ON c.ID = d.CURSO_ID
              WHERE p.ID = ? AND p.INSTITUICAO_ID = ? AND p.ATIVA = 1',
            [$provaId, $user->instituicaoId]
        )->fetch();
        if (!$prova) {
            throw ApiException::notFound('Prova não encontrada.');
        }
        if ($recuperacao && !$user->isAdmin()) {
            throw ApiException::forbidden('A prova de recuperação é liberada pela coordenação.');
        }
        $moduloId = (int) $prova['DISCIPLINA_ID'];

        if ($user->isAluno()) {
            $ctx = $this->contexto($user, (int) $prova['CURSO_ID']);
            if (!($ctx['status'][$moduloId] ?? null)?->podeFazerProva()) {
                throw ApiException::forbidden('Conclua todas as aulas deste módulo antes de realizar a prova.');
            }
        }

        $questoes = $this->db->run(
            'SELECT ID, CORRETA, VALOR FROM QUESTAO_PROVA WHERE PROVA_ID = ? AND INSTITUICAO_ID = ? ORDER BY ID',
            [$provaId, $user->instituicaoId]
        )->fetchAll();
        if (!$questoes) {
            throw new ApiException('Esta prova não possui questões.', 422, 'empty');
        }

        $jaFeita = $this->db->run(
            'SELECT COUNT(*) FROM NOTA_PROVA np JOIN QUESTAO_PROVA q ON q.ID = np.QUESTAO_PROVA_ID
              WHERE q.PROVA_ID = ? AND np.USUARIO_ID = ? AND np.RECUPERACAO = ?',
            [$provaId, $user->id, (int) $recuperacao]
        )->fetchColumn();
        if ((int) $jaFeita > 0) {
            throw new ApiException('Esta prova já foi realizada.', 409, 'conflict');
        }

        $resultado = $this->correcao->corrigir($questoes, $respostas);
        $status = $this->correcao->status($resultado['total'], $prova['MEDIA'] !== null ? (float) $prova['MEDIA'] : null);

        $this->db->beginTransaction();
        try {
            foreach ($resultado['itens'] as $questaoId => $item) {
                $flags = array_map(static fn ($op) => (int) ($item['resposta'] === $op), CorrecaoProva::OPCOES);
                $this->db->run(
                    'INSERT INTO NOTA_PROVA (INSTITUICAO_ID, QUESTAO_PROVA_ID, USUARIO_ID, ACERTOU_OPCAO_1, ACERTOU_OPCAO_2,
                        ACERTOU_OPCAO_3, ACERTOU_OPCAO_4, ACERTOU_OPCAO_5, NOTA, RECUPERACAO, DATA_CADASTRO)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$user->instituicaoId, $questaoId, $user->id, ...$flags, $item['nota'], (int) $recuperacao]
                );
            }
            $notaId = $this->db->run(
                'SELECT ID FROM NOTA WHERE USUARIO_ID = ? AND DISCIPLINA_ID = ? AND INSTITUICAO_ID = ?',
                [$user->id, $moduloId, $user->instituicaoId]
            )->fetchColumn();
            $coluna = $recuperacao ? 'NOTA_RECUPERACAO' : 'NOTA';
            if ($notaId) {
                $this->db->run(
                    "UPDATE NOTA SET {$coluna} = ?, LISTA_STATUS_NOTA_ID = ? WHERE ID = ?",
                    [$resultado['total'], $status, (int) $notaId]
                );
            } else {
                $this->db->run(
                    "INSERT INTO NOTA (INSTITUICAO_ID, DISCIPLINA_ID, USUARIO_ID, LISTA_STATUS_NOTA_ID, {$coluna}, FALTAS, DATA_CADASTRO)
                     VALUES (?, ?, ?, ?, ?, 0, NOW())",
                    [$user->instituicaoId, $moduloId, $user->id, $status, $resultado['total']]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->audit->log($user, $recuperacao ? 'prova_recuperacao' : 'prova', 'PROVA', $provaId, null,
            ['nota' => $resultado['total'], 'status' => $status]);

        return [
            'nota' => $resultado['total'],
            'status' => self::statusNome($status),
            'questoes' => array_map(
                static fn ($id, $i) => ['questaoId' => $id] + $i,
                array_keys($resultado['itens']),
                $resultado['itens']
            ),
        ];
    }

    public static function statusNome(int $id): string
    {
        return match ($id) {
            2 => 'APROVADO',
            3 => 'REPROVADO',
            default => 'CURSANDO',
        };
    }

    private function assertAlunoLiberado(AuthUser $user): void
    {
        $u = $this->usuarios->findById($user->id, $user->instituicaoId);
        if (!$u || (bool) $u['INATIVO'] || $this->usuarios->hasPendenciaFinanceira($user->id, $user->instituicaoId)) {
            throw ApiException::forbidden('Acesso ao painel suspenso. Procure a secretaria.');
        }
    }

    /** Carrega tudo que a regra da trilha precisa para um curso/usuário. */
    private function contexto(AuthUser $user, int $cursoId): array
    {
        $curso = $this->db->run('SELECT ID, NOME, MEDIA FROM CURSO WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$cursoId, $user->instituicaoId])->fetch();
        if (!$curso) {
            throw ApiException::notFound('Curso não encontrado.');
        }

        $dataMatricula = null;
        if ($user->isAluno()) {
            $this->assertAlunoLiberado($user);
            $mat = $this->db->run(
                'SELECT DATA_CADASTRO FROM USUARIO_CURSO WHERE USUARIO_ID = ? AND CURSO_ID = ? AND INSTITUICAO_ID = ?',
                [$user->id, $cursoId, $user->instituicaoId]
            )->fetch();
            if (!$mat) {
                throw ApiException::notFound('Curso não encontrado.');
            }
            $dataMatricula = $mat['DATA_CADASTRO'] ? new \DateTimeImmutable($mat['DATA_CADASTRO']) : null;
        }

        $modulos = $this->db->run(
            'SELECT d.ID, d.ORDEM, d.DURACAO_DIAS, d.PERIODO, d.LISTA_DISCIPLINA_ID, ld.VALOR AS DISCIPLINA, lp.PROFESSOR
               FROM DISCIPLINA d
               LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = d.LISTA_DISCIPLINA_ID
               LEFT JOIN LISTA_PROFESSOR lp ON lp.ID = d.LISTA_PROFESSOR_ID
              WHERE d.CURSO_ID = ? AND d.INSTITUICAO_ID = ?
              ORDER BY COALESCE(d.ORDEM, 2147483647), d.DATA_INICIO, d.ID',
            [$cursoId, $user->instituicaoId]
        )->fetchAll();
        $ids = array_map(static fn ($m) => (int) $m['ID'], $modulos);

        $videos = [];
        $concluidos = [];
        $tempos = [];
        $notas = [];
        $notasRows = [];
        $provas = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->run(
                "SELECT ID, DISCIPLINA_ID, TITULO, DESCRICAO, URL, YOUTUBE, VIMEO FROM VIDEO
                  WHERE DISCIPLINA_ID IN ({$in}) ORDER BY COALESCE(ORDEM, 2147483647), TITULO",
                $ids
            )->fetchAll() as $v) {
                $videos[(int) $v['DISCIPLINA_ID']][] = $v;
            }
            foreach ($this->db->run(
                "SELECT vv.VIDEO_ID, vv.STATUS, vv.TEMPO FROM VIDEO_VIEW vv JOIN VIDEO v ON v.ID = vv.VIDEO_ID
                  WHERE vv.USUARIO_ID = ? AND v.DISCIPLINA_ID IN ({$in})",
                [$user->id, ...$ids]
            )->fetchAll() as $vv) {
                if ((int) $vv['STATUS'] === 2) {
                    $concluidos[(int) $vv['VIDEO_ID']] = true;
                } elseif ($vv['TEMPO'] !== null && $vv['TEMPO'] !== '') {
                    $tempos[(int) $vv['VIDEO_ID']] = $vv['TEMPO'];
                }
            }
            foreach ($this->db->run(
                "SELECT DISCIPLINA_ID, LISTA_STATUS_NOTA_ID, NOTA AS NOTA1, NOTA_RECUPERACAO, FALTAS FROM NOTA
                  WHERE USUARIO_ID = ? AND DISCIPLINA_ID IN ({$in})",
                [$user->id, ...$ids]
            )->fetchAll() as $n) {
                $notas[(int) $n['DISCIPLINA_ID']] = (int) $n['LISTA_STATUS_NOTA_ID'];
                $notasRows[(int) $n['DISCIPLINA_ID']] = $n;
            }
            // uma prova ativa por módulo (a mais antiga, como PROVA[0] no legado)
            foreach ($this->db->run(
                "SELECT MIN(ID) AS ID, DISCIPLINA_ID FROM PROVA WHERE ATIVA = 1 AND DISCIPLINA_ID IN ({$in}) GROUP BY DISCIPLINA_ID",
                $ids
            )->fetchAll() as $p) {
                $provas[(int) $p['DISCIPLINA_ID']] = (int) $p['ID'];
            }
        }

        $entrada = array_map(static fn ($m) => [
            'id' => (int) $m['ID'],
            'duracaoDias' => $m['DURACAO_DIAS'] !== null ? (int) $m['DURACAO_DIAS'] : null,
            'videos' => array_map(static fn ($v) => (int) $v['ID'], $videos[(int) $m['ID']] ?? []),
        ], $modulos);

        $status = $this->trilha->calcular(
            $entrada,
            $dataMatricula,
            $concluidos,
            $notas,
            array_fill_keys(array_keys($provas), true),
            new \DateTimeImmutable(),
        );

        return compact('curso', 'dataMatricula', 'modulos', 'videos', 'concluidos', 'tempos', 'notas', 'notasRows', 'provas', 'status');
    }

    /** Questões SEM gabarito; após a realização, devolve o resultado por questão. */
    private function provaParaAluno(AuthUser $user, int $provaId, bool $disponivel): array
    {
        $questoes = $this->db->run(
            'SELECT ID, QUESTAO, OPCAO_1, OPCAO_2, OPCAO_3, OPCAO_4, OPCAO_5, VALOR FROM QUESTAO_PROVA WHERE PROVA_ID = ? ORDER BY ID',
            [$provaId]
        )->fetchAll();
        $respostas = [];
        foreach ($this->db->run(
            'SELECT np.QUESTAO_PROVA_ID, np.RECUPERACAO, np.NOTA, np.ACERTOU_OPCAO_1, np.ACERTOU_OPCAO_2, np.ACERTOU_OPCAO_3,
                    np.ACERTOU_OPCAO_4, np.ACERTOU_OPCAO_5
               FROM NOTA_PROVA np JOIN QUESTAO_PROVA q ON q.ID = np.QUESTAO_PROVA_ID
              WHERE q.PROVA_ID = ? AND np.USUARIO_ID = ?',
            [$provaId, $user->id]
        )->fetchAll() as $np) {
            $marcada = null;
            foreach (CorrecaoProva::OPCOES as $i => $op) {
                if ((int) $np['ACERTOU_OPCAO_' . ($i + 1)] === 1) {
                    $marcada = $op;
                }
            }
            $respostas[(int) $np['RECUPERACAO']][(int) $np['QUESTAO_PROVA_ID']] = [
                'resposta' => $marcada,
                'nota' => (float) $np['NOTA'],
                'acertou' => (float) $np['NOTA'] > 0,
            ];
        }

        return [
            'id' => $provaId,
            'disponivel' => $disponivel,
            'realizada' => isset($respostas[0]),
            'recuperacaoRealizada' => isset($respostas[1]),
            'valorTotal' => round(array_sum(array_map(static fn ($q) => (float) $q['VALOR'], $questoes)), 2),
            'questoes' => array_map(static function ($q) use ($respostas) {
                $opcoes = [];
                foreach (CorrecaoProva::OPCOES as $i => $op) {
                    $texto = $q['OPCAO_' . ($i + 1)];
                    if ($texto !== null && trim($texto) !== '') {
                        $opcoes[] = ['letra' => $op, 'texto' => $texto];
                    }
                }
                return [
                    'id' => (int) $q['ID'],
                    'enunciado' => $q['QUESTAO'],
                    'valor' => (float) $q['VALOR'],
                    'opcoes' => $opcoes,
                    'resultado' => $respostas[0][(int) $q['ID']] ?? null,
                    'resultadoRecuperacao' => $respostas[1][(int) $q['ID']] ?? null,
                ];
            }, $questoes),
        ];
    }

    /** @param list<int|string|null> $listaDisciplinaIds */
    private function arquivosPorDisciplina(AuthUser $user, array $listaDisciplinaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $listaDisciplinaIds))));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->db->run(
            "SELECT ID, LISTA_DISCIPLINA_ID, TITULO, DESCRICAO, URL FROM ARQUIVO
              WHERE INSTITUICAO_ID = ? AND LISTA_DISCIPLINA_ID IN ({$in}) ORDER BY TITULO",
            [$user->instituicaoId, ...$ids]
        )->fetchAll() as $a) {
            $out[(int) $a['LISTA_DISCIPLINA_ID']][] = [
                'id' => (int) $a['ID'],
                'titulo' => $a['TITULO'],
                'descricao' => $a['DESCRICAO'],
                'url' => ArquivoUrl::publica($a['URL'], (int) $a['ID']),
            ];
        }
        return $out;
    }
}
