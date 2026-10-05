<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Usuario\UsuarioRepository;
use App\Support\ApiException;
use App\Support\RateLimiter;

final class AuthService
{
    private const INVALID = 'E-mail ou senha inválidos.';

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenService $tokens,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly int $refreshTtl,
        private readonly bool $clearLegacyPlaintext,
    ) {
    }

    /**
     * @param int|null $instituicaoId instituição do subdomínio, usada para desempatar
     *                                e-mails repetidos entre instituições
     * @return array{access_token:string, expires_in:int, refresh_token:string, user:AuthUser}
     */
    public function login(string $email, string $senha, ?int $instituicaoId, string $ip, ?string $userAgent): array
    {
        $email = mb_strtolower(trim($email));
        $this->limiter->hit("login:ip:{$ip}", 30, 900);
        $this->limiter->hit("login:{$ip}:{$email}", 5, 300);

        $candidatos = $this->usuarios->findCredentialsByEmail($email);
        if ($instituicaoId !== null) {
            $daInstituicao = array_values(array_filter(
                $candidatos,
                static fn (array $u) => (int) $u['INSTITUICAO_ID'] === $instituicaoId
            ));
            if ($daInstituicao) {
                $candidatos = $daInstituicao;
            }
        }

        $row = null;
        foreach ($candidatos as $c) {
            if ($this->checkPassword($c, $senha)) {
                $row = $c;
                break;
            }
        }
        if ($row === null) {
            // gasta tempo equivalente para não revelar se o e-mail existe
            if (!$candidatos) {
                $this->hasher->hash($senha);
            }
            throw ApiException::unauthorized(self::INVALID);
        }

        $user = $this->toAuthUser($row);
        $this->assertCanLogin($row, $user);
        $this->limiter->clear("login:{$ip}:{$email}");

        return $this->issueSession($user, $ip, $userAgent);
    }

    /** Rotaciona o refresh token. Reuso de token já revogado derruba todas as sessões do usuário. */
    public function refresh(string $refreshToken, string $ip, ?string $userAgent): array
    {
        $stored = $this->refreshTokens->find($refreshToken);
        if ($stored === null) {
            throw ApiException::unauthorized('Sessão inválida.');
        }
        if ($stored['REVOGADO_EM'] !== null) {
            $this->refreshTokens->revokeAllForUser((int) $stored['USUARIO_ID']);
            throw ApiException::unauthorized('Sessão encerrada por segurança. Entre novamente.');
        }
        if ((int) $stored['EXPIRADO'] === 1) {
            throw ApiException::unauthorized('Sessão expirada. Entre novamente.');
        }

        $row = $this->usuarios->findCredentialsById((int) $stored['USUARIO_ID']);
        if ($row === null) {
            throw ApiException::unauthorized('Sessão inválida.');
        }
        $user = $this->toAuthUser($row);
        $this->assertCanLogin($row, $user);

        $this->refreshTokens->revoke((int) $stored['ID']);
        return $this->issueSession($user, $ip, $userAgent);
    }

    /** Revoga a sessão do cookie (funciona mesmo com o access token já expirado). */
    public function logout(?string $refreshToken): void
    {
        $stored = $refreshToken ? $this->refreshTokens->find($refreshToken) : null;
        if ($stored) {
            $this->refreshTokens->revoke((int) $stored['ID']);
            $this->usuarios->setOnline((int) $stored['USUARIO_ID'], false);
        }
    }

    public function changePassword(AuthUser $user, string $atual, string $nova): void
    {
        $row = $this->usuarios->findCredentialsById($user->id);
        if ($row === null || !$this->checkPassword($row, $atual)) {
            throw ApiException::validation(['senhaAtual' => 'Senha atual incorreta.']);
        }
        $this->setPassword($user->id, $nova);
    }

    /** Define nova senha e encerra todas as sessões abertas. */
    public function setPassword(int $usuarioId, string $nova): void
    {
        PasswordPolicy::assert($nova);
        $this->usuarios->updatePassword($usuarioId, $nova, $this->hasher->hash($nova), $this->clearLegacyPlaintext);
        $this->refreshTokens->revokeAllForUser($usuarioId);
    }

    /** Verifica a senha; migra senha legada em texto para hash no primeiro login. */
    private function checkPassword(array $row, string $senha): bool
    {
        $hash = $row['SENHA_HASH'] ?? null;
        if ($hash) {
            if (!$this->hasher->verify($senha, $hash)) {
                return false;
            }
            if ($this->hasher->needsRehash($hash)) {
                $this->usuarios->savePasswordHash((int) $row['ID'], $this->hasher->hash($senha), $this->clearLegacyPlaintext);
            }
            return true;
        }
        if ($this->hasher->verifyLegacy($senha, $row['SENHA'] ?? null)) {
            $this->usuarios->savePasswordHash((int) $row['ID'], $this->hasher->hash($senha), $this->clearLegacyPlaintext);
            return true;
        }
        return false;
    }

    private function assertCanLogin(array $row, AuthUser $user): void
    {
        // Regra legada: instituição inativa bloqueia alunos e professores (admin entra para regularizar).
        if (!(bool) $row['INSTITUICAO_ATIVA'] && $user->is(Perfil::Aluno, Perfil::Professor)) {
            throw ApiException::forbidden('Acesso da instituição suspenso. Procure a secretaria.');
        }
    }

    private function toAuthUser(array $row): AuthUser
    {
        return new AuthUser(
            (int) $row['ID'],
            (int) $row['INSTITUICAO_ID'],
            (int) $row['LISTA_PERFIL_ID'],
            (bool) $row['MASTER'],
        );
    }

    private function issueSession(AuthUser $user, string $ip, ?string $userAgent): array
    {
        $refresh = bin2hex(random_bytes(32));
        $this->refreshTokens->create($user->id, $refresh, $this->refreshTtl, $userAgent, $ip);
        $this->usuarios->setOnline($user->id, true);

        return [
            'access_token'  => $this->tokens->issue($user),
            'expires_in'    => $this->tokens->ttl(),
            'refresh_token' => $refresh,
            'user'          => $user,
        ];
    }
}
