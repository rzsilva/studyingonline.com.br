<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Usuario\UsuarioRepository;
use App\Integrations\Mail\Mailer;
use App\Support\ApiException;
use App\Support\RateLimiter;
use App\Support\Connection;

/**
 * Recuperação de senha por link de uso único (o legado gerava e enviava a senha por e-mail).
 */
final class PasswordResetService
{
    private const TTL_MINUTES = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly UsuarioRepository $usuarios,
        private readonly AuthService $auth,
        private readonly Mailer $mailer,
        private readonly RateLimiter $limiter,
        private readonly string $appUrl,
    ) {
    }

    /** Sempre "sucede" para não revelar quais e-mails existem. */
    public function request(string $email, ?int $instituicaoId, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $this->limiter->hit("reset:{$ip}", 10, 3600);
        $this->limiter->hit("reset:{$email}", 3, 3600);

        $candidatos = $this->usuarios->findCredentialsByEmail($email);
        if ($instituicaoId !== null) {
            $candidatos = array_filter($candidatos, static fn ($u) => (int) $u['INSTITUICAO_ID'] === $instituicaoId) ?: $candidatos;
        }
        $user = reset($candidatos);
        if (!$user) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->db->prepare(
            'INSERT INTO PASSWORD_RESET (USUARIO_ID, TOKEN_HASH, EXPIRA_EM, CRIADO_EM)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())'
        )->execute([(int) $user['ID'], hash('sha256', $token), self::TTL_MINUTES]);

        $link = "{$this->appUrl}/redefinir-senha?token={$token}";
        $this->mailer->send(
            $email,
            'Redefinição de senha',
            '<p>Recebemos um pedido para redefinir sua senha.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Clique aqui para criar uma nova senha</a>.</p>'
            . '<p>O link vale por ' . self::TTL_MINUTES . ' minutos. Se não foi você, ignore este e-mail.</p>'
        );
    }

    public function reset(string $token, string $novaSenha): void
    {
        PasswordPolicy::assert($novaSenha);

        $st = $this->db->prepare(
            'SELECT ID, USUARIO_ID FROM PASSWORD_RESET
              WHERE TOKEN_HASH = ? AND USADO_EM IS NULL AND EXPIRA_EM > NOW()'
        );
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch();
        if (!$row) {
            throw new ApiException('Link inválido ou expirado. Solicite um novo.', 400, 'invalid_token');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE PASSWORD_RESET SET USADO_EM = NOW() WHERE USUARIO_ID = ? AND USADO_EM IS NULL')
                ->execute([(int) $row['USUARIO_ID']]);
            $this->auth->setPassword((int) $row['USUARIO_ID'], $novaSenha);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
