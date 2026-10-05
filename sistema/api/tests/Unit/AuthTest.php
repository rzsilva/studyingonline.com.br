<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthUser;
use App\Domain\Auth\PasswordHasher;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\Perfil;
use App\Domain\Auth\TokenService;
use App\Support\ApiException;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testTokenRoundTrip(): void
    {
        $svc = new TokenService(self::SECRET, 900);
        $user = $svc->parse($svc->issue(new AuthUser(42, 7, Perfil::Aluno->value, false)));

        self::assertSame(42, $user->id);
        self::assertSame(7, $user->instituicaoId);
        self::assertTrue($user->isAluno());
        self::assertFalse($user->master);
    }

    public function testTokenWithOtherSecretIsRejected(): void
    {
        $forged = (new TokenService(str_repeat('x', 48), 900))->issue(new AuthUser(1, 1, 1, true));

        $this->expectException(ApiException::class);
        (new TokenService(self::SECRET, 900))->parse($forged);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $jwt = JWT::encode(
            ['iss' => 'studyingonline', 'sub' => '1', 'inst' => 1, 'prf' => 1, 'mst' => false, 'exp' => time() - 10],
            self::SECRET,
            'HS256'
        );
        $this->expectException(ApiException::class);
        (new TokenService(self::SECRET, 900))->parse($jwt);
    }

    public function testShortSecretIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        new TokenService('curto', 900);
    }

    public function testPasswordHashAndLegacyCompare(): void
    {
        $h = new PasswordHasher();
        $hash = $h->hash('Segredo123');

        self::assertNotSame('Segredo123', $hash);
        self::assertTrue($h->verify('Segredo123', $hash));
        self::assertFalse($h->verify('errada', $hash));
        self::assertTrue($h->verifyLegacy('abc', 'abc'));
        self::assertFalse($h->verifyLegacy('abc', null));
        self::assertFalse($h->verifyLegacy('', ''));
    }

    public function testAlunoOnlyAccessesOwnData(): void
    {
        $aluno = new AuthUser(10, 1, Perfil::Aluno->value, false);
        $admin = new AuthUser(1, 1, Perfil::Administrador->value, false);

        self::assertTrue($aluno->canAccessUser(10));
        self::assertFalse($aluno->canAccessUser(11));
        self::assertTrue($admin->canAccessUser(11));
    }

    /** @dataProvider weakPasswords */
    public function testPasswordPolicyRejectsWeak(string $senha): void
    {
        $this->expectException(ApiException::class);
        PasswordPolicy::assert($senha);
    }

    public static function weakPasswords(): array
    {
        return [['abc12'], ['somenteletras'], ['12345678']];
    }

    public function testPasswordPolicyAcceptsStrong(): void
    {
        PasswordPolicy::assert('Studying2026');
        $this->addToAssertionCount(1);
    }
}
