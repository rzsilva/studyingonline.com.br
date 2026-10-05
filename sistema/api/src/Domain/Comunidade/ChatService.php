<?php

declare(strict_types=1);

namespace App\Domain\Comunidade;

use App\Domain\Auth\AuthUser;
use App\Domain\Auth\Perfil;
use App\Support\ApiException;
use App\Support\Connection;
use App\Support\RateLimiter;

/**
 * Chat 1:1 por polling (hospedagem compartilhada não mantém WebSocket).
 * Correções em relação ao legado:
 *  - remetente vem do token (antes vinha do cliente: dava para falar "em nome" de outro);
 *  - só se lê a conversa da qual se participa (antes qualquer par de IDs);
 *  - lista de contatos devolve só nome/foto/perfil (antes o USUARIO inteiro, com SENHA).
 * Regra de contatos do legado: o aluno conversa com a equipe; a equipe com todos.
 */
final class ChatService
{
    private const MAX = 2000;

    public function __construct(private readonly Connection $db, private readonly RateLimiter $limiter)
    {
    }

    public function contatos(AuthUser $user): array
    {
        $filtroPerfil = $user->isAluno() ? ' AND u.LISTA_PERFIL_ID <> ' . Perfil::Aluno->value : '';
        $rows = $this->db->run(
            "SELECT u.ID, u.NOME, u.URL, u.ONLINE, p.VALOR AS PERFIL,
                    (SELECT COUNT(*) FROM CHAT_MENSAGENS m
                      WHERE m.USUARIO_SEND_ID = u.ID AND m.USUARIO_RECEIVE_ID = ? AND m.LIDA_EM IS NULL) AS NAO_LIDAS,
                    (SELECT MAX(m.ID) FROM CHAT_MENSAGENS m
                      WHERE (m.USUARIO_SEND_ID = u.ID AND m.USUARIO_RECEIVE_ID = ?)
                         OR (m.USUARIO_SEND_ID = ? AND m.USUARIO_RECEIVE_ID = u.ID)) AS ULTIMA
               FROM USUARIO u LEFT JOIN LISTA_PERFIL p ON p.ID = u.LISTA_PERFIL_ID
              WHERE u.INSTITUICAO_ID = ? AND u.ID <> ? AND u.INATIVO = 0 {$filtroPerfil}
              ORDER BY (ULTIMA IS NULL), ULTIMA DESC, u.NOME
              LIMIT 500",
            [$user->id, $user->id, $user->id, $user->instituicaoId, $user->id]
        )->fetchAll();

        return array_map(static fn ($r) => [
            'id' => (int) $r['ID'],
            'nome' => $r['NOME'],
            'foto' => $r['URL'],
            'perfil' => $r['PERFIL'],
            'online' => (bool) $r['ONLINE'],
            'naoLidas' => (int) $r['NAO_LIDAS'],
        ], $rows);
    }

    /** Mensagens da conversa (incremental com $depoisDe) e marca as recebidas como lidas. */
    public function mensagens(AuthUser $user, int $contatoId, int $depoisDe): array
    {
        $this->assertContato($user, $contatoId);
        $rows = $this->db->run(
            'SELECT ID, USUARIO_SEND_ID, MENSAGEM, DATA_CADASTRO, LIDA_EM FROM CHAT_MENSAGENS
              WHERE INSTITUICAO_ID = ? AND ID > ?
                AND ((USUARIO_SEND_ID = ? AND USUARIO_RECEIVE_ID = ?) OR (USUARIO_SEND_ID = ? AND USUARIO_RECEIVE_ID = ?))
              ORDER BY ID DESC LIMIT 200',
            [$user->instituicaoId, $depoisDe, $user->id, $contatoId, $contatoId, $user->id]
        )->fetchAll();

        $marcadas = $this->db->run(
            'UPDATE CHAT_MENSAGENS SET LIDA_EM = NOW()
              WHERE USUARIO_SEND_ID = ? AND USUARIO_RECEIVE_ID = ? AND LIDA_EM IS NULL',
            [$contatoId, $user->id]
        )->rowCount();
        if ($marcadas > 0) {
            $this->sincronizarContador($user->id);
        }

        return array_map(static fn ($r) => [
            'id' => (int) $r['ID'],
            'minha' => (int) $r['USUARIO_SEND_ID'] === $user->id,
            'texto' => $r['MENSAGEM'],
            'data' => $r['DATA_CADASTRO'],
            'lida' => $r['LIDA_EM'] !== null,
        ], array_reverse($rows));
    }

    public function enviar(AuthUser $user, int $contatoId, string $texto): array
    {
        $this->assertContato($user, $contatoId);
        $texto = trim($texto);
        if ($texto === '') {
            throw ApiException::validation(['texto' => 'Digite uma mensagem.']);
        }
        if (mb_strlen($texto) > self::MAX) {
            throw ApiException::validation(['texto' => 'Máximo de ' . self::MAX . ' caracteres.']);
        }
        $this->limiter->hit("chat:{$user->id}", 30, 60);

        $this->db->run(
            'INSERT INTO CHAT_MENSAGENS (INSTITUICAO_ID, USUARIO_SEND_ID, USUARIO_RECEIVE_ID, MENSAGEM, DATA_CADASTRO) VALUES (?, ?, ?, ?, NOW())',
            [$user->instituicaoId, $user->id, $contatoId, $texto]
        );
        $id = $this->db->lastInsertId();
        $this->sincronizarContador($contatoId);
        return ['id' => $id, 'minha' => true, 'texto' => $texto, 'data' => date('Y-m-d H:i:s'), 'lida' => false];
    }

    /** USUARIO.MSG_NO_VIEW (usado no menu/legado) = mensagens não lidas recebidas. */
    private function sincronizarContador(int $usuarioId): void
    {
        $this->db->run(
            'UPDATE USUARIO SET MSG_NO_VIEW = (SELECT COUNT(*) FROM CHAT_MENSAGENS WHERE USUARIO_RECEIVE_ID = ? AND LIDA_EM IS NULL) WHERE ID = ?',
            [$usuarioId, $usuarioId]
        );
    }

    private function assertContato(AuthUser $user, int $contatoId): void
    {
        if ($contatoId === $user->id) {
            throw ApiException::validation(['contato' => 'Escolha outro usuário.']);
        }
        $c = $this->db->run('SELECT LISTA_PERFIL_ID FROM USUARIO WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$contatoId, $user->instituicaoId])->fetch();
        if (!$c) {
            throw ApiException::notFound('Contato não encontrado.');
        }
        if ($user->isAluno() && (int) $c['LISTA_PERFIL_ID'] === Perfil::Aluno->value) {
            throw ApiException::forbidden('O chat do aluno é com a coordenação e os professores.');
        }
    }
}
