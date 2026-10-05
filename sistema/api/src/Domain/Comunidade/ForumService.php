<?php

declare(strict_types=1);

namespace App\Domain\Comunidade;

use App\Domain\Auth\AuthUser;
use App\Domain\Usuario\UsuarioRepository;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;

/**
 * Fórum por disciplina (FORUM = tópico, FORUM_DISCURSAO = resposta).
 * Correções em relação ao legado: só o autor (ou a equipe) edita/exclui; o Edit legado
 * aceitava a entidade inteira do cliente (inclusive USUARIO_ID); aluno bloqueado não participa.
 */
final class ForumService
{
    private const MAX_TEXTO = 10000;

    public function __construct(
        private readonly Connection $db,
        private readonly UsuarioRepository $usuarios,
        private readonly Audit $audit,
    ) {
    }

    public function topicos(AuthUser $user, ?int $disciplinaId, string $q, int $page): array
    {
        $this->assertParticipa($user);
        $where = ['f.INSTITUICAO_ID = ?'];
        $params = [$user->instituicaoId];
        if ($disciplinaId) {
            $where[] = 'f.LISTA_DISCIPLINA_ID = ?';
            $params[] = $disciplinaId;
        }
        if ($q !== '') {
            $where[] = '(f.TITULO LIKE ? OR f.DESCRICAO LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->run("SELECT COUNT(*) FROM FORUM f WHERE {$w}", $params)->fetchColumn();
        $off = (max(1, $page) - 1) * 20;
        $rows = $this->db->run(
            "SELECT f.ID, f.TITULO, f.DESCRICAO, f.VISUALIZACOES, f.DATA_CADASTRO, f.EDITADO, f.USUARIO_ID,
                    u.NOME AS AUTOR, u.URL AS AUTOR_FOTO, ld.VALOR AS DISCIPLINA, f.LISTA_DISCIPLINA_ID,
                    (SELECT COUNT(*) FROM FORUM_DISCURSAO d WHERE d.FORUM_ID = f.ID) AS RESPOSTAS,
                    (SELECT MAX(d.DATA_CADASTRO) FROM FORUM_DISCURSAO d WHERE d.FORUM_ID = f.ID) AS ULTIMA_RESPOSTA
               FROM FORUM f
               LEFT JOIN USUARIO u ON u.ID = f.USUARIO_ID
               LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = f.LISTA_DISCIPLINA_ID
              WHERE {$w}
              ORDER BY COALESCE((SELECT MAX(d.DATA_CADASTRO) FROM FORUM_DISCURSAO d WHERE d.FORUM_ID = f.ID), f.DATA_CADASTRO) DESC
              LIMIT 20 OFFSET {$off}",
            $params
        )->fetchAll();

        return [array_map(fn ($r) => $this->mapTopico($user, $r), $rows), ['page' => max(1, $page), 'perPage' => 20, 'total' => $total]];
    }

    public function topico(AuthUser $user, int $id): array
    {
        $this->assertParticipa($user);
        $t = $this->findTopico($user, $id, true);
        $this->db->run('UPDATE FORUM SET VISUALIZACOES = COALESCE(VISUALIZACOES, 0) + 1 WHERE ID = ?', [$id]);
        $t['VISUALIZACOES'] = (int) $t['VISUALIZACOES'] + 1;

        $respostas = $this->db->run(
            'SELECT d.ID, d.DISCURSAO, d.DATA_CADASTRO, d.EDITADO, d.DATA_EDICAO, d.USUARIO_ID,
                    u.NOME AS AUTOR, u.URL AS AUTOR_FOTO, p.VALOR AS AUTOR_PERFIL
               FROM FORUM_DISCURSAO d
               LEFT JOIN USUARIO u ON u.ID = d.USUARIO_ID
               LEFT JOIN LISTA_PERFIL p ON p.ID = u.LISTA_PERFIL_ID
              WHERE d.FORUM_ID = ? AND d.INSTITUICAO_ID = ?
              ORDER BY d.DATA_CADASTRO, d.ID',
            [$id, $user->instituicaoId]
        )->fetchAll();

        // array_merge (e não +) para a lista substituir a contagem "respostas" do resumo
        return array_merge($this->mapTopico($user, $t), [
            'respostas' => array_map(fn ($r) => [
                'id' => (int) $r['ID'],
                'texto' => $r['DISCURSAO'],
                'autor' => $r['AUTOR'],
                'autorFoto' => $r['AUTOR_FOTO'],
                'autorPerfil' => $r['AUTOR_PERFIL'],
                'data' => $r['DATA_CADASTRO'],
                'editado' => (bool) $r['EDITADO'],
                'podeEditar' => $this->pode($user, (int) $r['USUARIO_ID']),
            ], $respostas),
        ]);
    }

    public function criarTopico(AuthUser $user, array $in): array
    {
        $this->assertParticipa($user);
        [$titulo, $descricao] = [$this->texto($in, 'titulo', 200), $this->texto($in, 'descricao', self::MAX_TEXTO)];
        $disciplina = (int) ($in['disciplinaId'] ?? 0);
        if (!$this->db->run('SELECT 1 FROM LISTA_DISCIPLINA WHERE ID = ? AND INSTITUICAO_ID = ?', [$disciplina, $user->instituicaoId])->fetchColumn()) {
            throw ApiException::validation(['disciplinaId' => 'Selecione a disciplina.']);
        }
        $this->db->run(
            'INSERT INTO FORUM (INSTITUICAO_ID, USUARIO_ID, LISTA_DISCIPLINA_ID, TITULO, DESCRICAO, VISUALIZACOES, POSTAGENS, DATA_CADASTRO, EDITADO)
             VALUES (?, ?, ?, ?, ?, 0, 0, NOW(), 0)',
            [$user->instituicaoId, $user->id, $disciplina, $titulo, $descricao]
        );
        return ['id' => $this->db->lastInsertId()];
    }

    public function editarTopico(AuthUser $user, int $id, array $in): void
    {
        $t = $this->findTopico($user, $id);
        $this->assertPode($user, (int) $t['USUARIO_ID']);
        $this->db->run(
            'UPDATE FORUM SET TITULO = ?, DESCRICAO = ?, EDITADO = 1, DATA_EDICAO = NOW() WHERE ID = ?',
            [$this->texto($in, 'titulo', 200), $this->texto($in, 'descricao', self::MAX_TEXTO), $id]
        );
    }

    public function excluirTopico(AuthUser $user, int $id): void
    {
        $t = $this->findTopico($user, $id);
        $this->assertPode($user, (int) $t['USUARIO_ID']);
        $this->db->beginTransaction();
        try {
            $this->db->run('DELETE FROM FORUM_DISCURSAO WHERE FORUM_ID = ?', [$id]);
            $this->db->run('DELETE FROM FORUM WHERE ID = ?', [$id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->audit->log($user, 'excluir', 'FORUM', $id, null, ['titulo' => $t['TITULO']]);
    }

    public function responder(AuthUser $user, int $topicoId, array $in): array
    {
        $this->assertParticipa($user);
        $this->findTopico($user, $topicoId);
        $texto = $this->texto($in, 'texto', self::MAX_TEXTO);
        $this->db->run(
            'INSERT INTO FORUM_DISCURSAO (INSTITUICAO_ID, USUARIO_ID, FORUM_ID, DISCURSAO, DATA_CADASTRO, EDITADO) VALUES (?, ?, ?, ?, NOW(), 0)',
            [$user->instituicaoId, $user->id, $topicoId, $texto]
        );
        $id = $this->db->lastInsertId();
        $this->db->run('UPDATE FORUM SET POSTAGENS = COALESCE(POSTAGENS, 0) + 1 WHERE ID = ?', [$topicoId]);
        return ['id' => $id];
    }

    public function editarResposta(AuthUser $user, int $id, array $in): void
    {
        $r = $this->findResposta($user, $id);
        $this->assertPode($user, (int) $r['USUARIO_ID']);
        $this->db->run('UPDATE FORUM_DISCURSAO SET DISCURSAO = ?, EDITADO = 1, DATA_EDICAO = NOW() WHERE ID = ?',
            [$this->texto($in, 'texto', self::MAX_TEXTO), $id]);
    }

    public function excluirResposta(AuthUser $user, int $id): void
    {
        $r = $this->findResposta($user, $id);
        $this->assertPode($user, (int) $r['USUARIO_ID']);
        $this->db->run('DELETE FROM FORUM_DISCURSAO WHERE ID = ?', [$id]);
        $this->db->run('UPDATE FORUM SET POSTAGENS = GREATEST(COALESCE(POSTAGENS, 1) - 1, 0) WHERE ID = ?', [(int) $r['FORUM_ID']]);
    }

    /** Autor ou equipe (admin/professor modera). */
    private function pode(AuthUser $user, int $autorId): bool
    {
        return $autorId === $user->id || !$user->isAluno();
    }

    private function assertPode(AuthUser $user, int $autorId): void
    {
        if (!$this->pode($user, $autorId)) {
            throw ApiException::forbidden('Somente o autor pode alterar esta publicação.');
        }
    }

    /** Como no menu legado: aluno inativo ou com pendência não usa o fórum. */
    private function assertParticipa(AuthUser $user): void
    {
        if (!$user->isAluno()) {
            return;
        }
        $u = $this->usuarios->findById($user->id, $user->instituicaoId);
        if (!$u || (bool) $u['INATIVO'] || $this->usuarios->hasPendenciaFinanceira($user->id, $user->instituicaoId)) {
            throw ApiException::forbidden('Acesso ao fórum suspenso. Procure a secretaria.');
        }
    }

    private function findTopico(AuthUser $user, int $id, bool $full = false): array
    {
        $t = $this->db->run(
            'SELECT f.ID, f.TITULO, f.DESCRICAO, f.VISUALIZACOES, f.DATA_CADASTRO, f.EDITADO, f.USUARIO_ID, f.LISTA_DISCIPLINA_ID,
                    u.NOME AS AUTOR, u.URL AS AUTOR_FOTO, ld.VALOR AS DISCIPLINA,
                    (SELECT COUNT(*) FROM FORUM_DISCURSAO d WHERE d.FORUM_ID = f.ID) AS RESPOSTAS, NULL AS ULTIMA_RESPOSTA
               FROM FORUM f LEFT JOIN USUARIO u ON u.ID = f.USUARIO_ID
               LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = f.LISTA_DISCIPLINA_ID
              WHERE f.ID = ? AND f.INSTITUICAO_ID = ?',
            [$id, $user->instituicaoId]
        )->fetch();
        if (!$t) {
            throw ApiException::notFound('Tópico não encontrado.');
        }
        return $t;
    }

    private function findResposta(AuthUser $user, int $id): array
    {
        $r = $this->db->run('SELECT ID, USUARIO_ID, FORUM_ID FROM FORUM_DISCURSAO WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$id, $user->instituicaoId])->fetch();
        if (!$r) {
            throw ApiException::notFound('Resposta não encontrada.');
        }
        return $r;
    }

    private function mapTopico(AuthUser $user, array $r): array
    {
        return [
            'id' => (int) $r['ID'],
            'titulo' => $r['TITULO'],
            'descricao' => $r['DESCRICAO'],
            'disciplinaId' => (int) $r['LISTA_DISCIPLINA_ID'],
            'disciplina' => $r['DISCIPLINA'],
            'autor' => $r['AUTOR'],
            'autorFoto' => $r['AUTOR_FOTO'],
            'data' => $r['DATA_CADASTRO'],
            'editado' => (bool) $r['EDITADO'],
            'visualizacoes' => (int) $r['VISUALIZACOES'],
            'respostas' => (int) $r['RESPOSTAS'],
            'ultimaResposta' => $r['ULTIMA_RESPOSTA'],
            'podeEditar' => $this->pode($user, (int) $r['USUARIO_ID']),
        ];
    }

    private function texto(array $in, string $campo, int $max): string
    {
        $v = trim((string) ($in[$campo] ?? ''));
        if ($v === '') {
            throw ApiException::validation([$campo => 'Campo obrigatório.']);
        }
        if (mb_strlen($v) > $max) {
            throw ApiException::validation([$campo => "Máximo de {$max} caracteres."]);
        }
        return $v;
    }
}
