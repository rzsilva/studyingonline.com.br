<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Crud\Cpf;
use App\Support\MailTemplate;
use PHPUnit\Framework\TestCase;

final class SecretariaTest extends TestCase
{
    /** @dataProvider cpfs */
    public function testValidacaoCpf(string $cpf, bool $esperado): void
    {
        self::assertSame($esperado, Cpf::valido($cpf));
    }

    public static function cpfs(): array
    {
        return [
            'válido sem máscara' => ['52998224725', true],
            'válido com máscara' => ['529.982.247-25', true],
            'dígito errado' => ['52998224724', false],
            'sequência repetida' => ['111.111.111-11', false],
            'curto' => ['123', false],
        ];
    }

    public function testCpfNoFormatoDoLegado(): void
    {
        self::assertSame('529.982.247-25', Cpf::formatar('52998224725'));
    }

    public function testEmailEscapaConteudoDoUsuario(): void
    {
        $html = MailTemplate::render('<b>Título</b>', '<p>' . MailTemplate::e('<script>x</script>') . '</p>', 'Escola & Cia', null, null);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;b&gt;Título', $html);
        self::assertStringContainsString('Escola &amp; Cia', $html);
    }
}
