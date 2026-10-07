<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Segredo;
use PHPUnit\Framework\TestCase;

final class SegredoTest extends TestCase
{
    private const CHAVE = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    public function testCifraEAbre(): void
    {
        $s = new Segredo(self::CHAVE, true);
        $c = $s->cifrar('TOKEN-SECRETO');
        self::assertStringStartsWith('enc:v1:', $c);
        self::assertStringNotContainsString('TOKEN', $c);
        self::assertSame('TOKEN-SECRETO', $s->abrir($c));
        self::assertNotSame($c, $s->cifrar('TOKEN-SECRETO'), 'IV aleatório');
    }

    public function testTextoDoLegadoPassaDireto(): void
    {
        self::assertSame('texto-puro', (new Segredo('', false))->abrir('texto-puro'));
        self::assertNull((new Segredo('', false))->abrir(null));
    }

    public function testSoCifraAoGravarDepoisDaVirada(): void
    {
        self::assertSame('x', (new Segredo(self::CHAVE, false))->paraGravar('x'));
        self::assertStringStartsWith('enc:v1:', (new Segredo(self::CHAVE, true))->paraGravar('x'));
    }

    public function testChaveErradaOuAdulteracaoFalha(): void
    {
        $c = (new Segredo(self::CHAVE, true))->cifrar('abc');
        $this->expectException(\RuntimeException::class);
        (new Segredo(str_repeat('f', 64), true))->abrir($c);
    }

    public function testCifradoSemChaveFalha(): void
    {
        $c = (new Segredo(self::CHAVE, true))->cifrar('abc');
        $this->expectException(\RuntimeException::class);
        (new Segredo('', false))->abrir($c);
    }
}
