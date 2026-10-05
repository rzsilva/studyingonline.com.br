<?php

declare(strict_types=1);

namespace App\Domain\Trilha;

final class ModuloStatus
{
    public bool $conteudoConcluido = false;
    public bool $provaOk = false;
    public bool $concluido = false;
    public bool $tempoLiberado = false;
    public bool $liberadoLegado = false;
    public bool $liberado = false;
    public ?\DateTimeImmutable $dataLiberacaoPrevista = null;
    /** @var array<int,bool> */
    public array $videosLiberados = [];

    public function __construct(public readonly int $moduloId)
    {
    }

    /** Prova só pode ser feita com o módulo liberado e todas as aulas assistidas. */
    public function podeFazerProva(): bool
    {
        return $this->liberado && $this->conteudoConcluido;
    }
}
