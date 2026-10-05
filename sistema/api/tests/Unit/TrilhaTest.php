<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Trilha\CorrecaoProva;
use App\Domain\Trilha\TrilhaService;
use PHPUnit\Framework\TestCase;

final class TrilhaTest extends TestCase
{
    private TrilhaService $svc;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->svc = new TrilhaService();
        $this->hoje = new \DateTimeImmutable('2026-10-05 12:00:00');
    }

    /** 3 módulos de 10 dias; módulo 1 tem vídeos 11,12; módulo 2 tem 21; módulo 3 tem 31 */
    private function modulos(): array
    {
        return [
            ['id' => 1, 'duracaoDias' => 10, 'videos' => [11, 12]],
            ['id' => 2, 'duracaoDias' => 10, 'videos' => [21]],
            ['id' => 3, 'duracaoDias' => 10, 'videos' => [31]],
        ];
    }

    private function diasAtras(int $d): \DateTimeImmutable
    {
        return $this->hoje->modify("-{$d} days");
    }

    public function testPrimeiroModuloLiberadoEVideosSequenciais(): void
    {
        $r = $this->svc->calcular($this->modulos(), $this->hoje, [], [], [], $this->hoje);

        self::assertTrue($r[1]->liberado);
        self::assertSame([11 => true, 12 => false], $r[1]->videosLiberados);
        self::assertFalse($r[2]->liberado);
        self::assertFalse($r[1]->podeFazerProva());
    }

    public function testSegundoVideoLiberaAposAssistirPrimeiro(): void
    {
        $r = $this->svc->calcular($this->modulos(), $this->hoje, [11 => true], [], [], $this->hoje);
        self::assertTrue($r[1]->videosLiberados[12]);
    }

    public function testConcluidoMasSemTempoNaoLibera(): void
    {
        // matrícula há 5 dias; módulo 2 só libera no dia 10
        $r = $this->svc->calcular($this->modulos(), $this->diasAtras(5), [11 => true, 12 => true], [], [], $this->hoje);

        self::assertTrue($r[1]->concluido);
        self::assertFalse($r[2]->liberado);
        self::assertEquals($this->diasAtras(5)->modify('+10 days'), $r[2]->dataLiberacaoPrevista);
    }

    public function testConcluidoComTempoLibera(): void
    {
        $r = $this->svc->calcular($this->modulos(), $this->diasAtras(15), [11 => true, 12 => true], [], [], $this->hoje);
        self::assertTrue($r[2]->liberado);
        self::assertFalse($r[3]->liberado, 'módulo 3 depende do 2 concluído e de 20 dias');
    }

    public function testTempoSemConcluirNaoLibera(): void
    {
        $r = $this->svc->calcular($this->modulos(), $this->diasAtras(100), [11 => true], [], [], $this->hoje);
        self::assertFalse($r[2]->liberado);
    }

    public function testProvaAtivaExigeAprovacao(): void
    {
        $views = [11 => true, 12 => true];
        $comProva = [1 => true];

        $semNota = $this->svc->calcular($this->modulos(), $this->diasAtras(15), $views, [], $comProva, $this->hoje);
        self::assertTrue($semNota[1]->podeFazerProva());
        self::assertFalse($semNota[2]->liberado);

        $reprovado = $this->svc->calcular($this->modulos(), $this->diasAtras(15), $views, [1 => 3], $comProva, $this->hoje);
        self::assertFalse($reprovado[2]->liberado);

        $aprovado = $this->svc->calcular($this->modulos(), $this->diasAtras(15), $views, [1 => 2], $comProva, $this->hoje);
        self::assertTrue($aprovado[2]->liberado);
    }

    public function testNotaLegadaLiberaModuloIndependenteDaRegra(): void
    {
        $r = $this->svc->calcular($this->modulos(), $this->hoje, [], [3 => 1], [], $this->hoje);
        self::assertFalse($r[2]->liberado);
        self::assertTrue($r[3]->liberado);
        self::assertTrue($r[3]->liberadoLegado);
    }

    public function testDuracaoPadraoTrintaDias(): void
    {
        $mods = [['id' => 1, 'duracaoDias' => null, 'videos' => []], ['id' => 2, 'duracaoDias' => null, 'videos' => []]];
        self::assertFalse($this->svc->calcular($mods, $this->diasAtras(29), [], [], [], $this->hoje)[2]->liberado);
        self::assertTrue($this->svc->calcular($mods, $this->diasAtras(30), [], [], [], $this->hoje)[2]->liberado);
    }

    public function testCorrecaoNoServidor(): void
    {
        $c = new CorrecaoProva();
        $questoes = [
            ['ID' => 1, 'CORRETA' => 'A', 'VALOR' => '2.5'],
            ['ID' => 2, 'CORRETA' => 'C', 'VALOR' => '2.5'],
            ['ID' => 3, 'CORRETA' => 'E', 'VALOR' => '5'],
        ];
        $r = $c->corrigir($questoes, [1 => 'a', 2 => 'B', 3 => 'X', 99 => 'A']);

        self::assertSame(2.5, $r['total']);
        self::assertTrue($r['itens'][1]['acertou']);
        self::assertFalse($r['itens'][2]['acertou']);
        self::assertNull($r['itens'][3]['resposta'], 'opção inválida é descartada');
        self::assertArrayNotHasKey(99, $r['itens'], 'questão de outra prova é ignorada');

        self::assertSame(2, $c->status(7.0, 7.0));
        self::assertSame(3, $c->status(6.99, 7.0));
        self::assertSame(2, $c->status(0.0, null));
    }
}
