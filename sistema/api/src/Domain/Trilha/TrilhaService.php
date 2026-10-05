<?php

declare(strict_types=1);

namespace App\Domain\Trilha;

/**
 * Porte fiel de Adaline.Webservice/Services/ModuloLiberacaoService.cs (regra híbrida):
 *
 * - Módulos em ordem (ORDEM, DATA_INICIO, ID). O 1º está sempre liberado por sequência.
 * - O módulo N+1 libera quando o módulo N está liberado E concluído
 *   (todos os vídeos com VIDEO_VIEW.STATUS = 2 e, se houver PROVA ativa, NOTA aprovada)
 *   E já passou o tempo mínimo: matrícula + soma de DURACAO_DIAS dos módulos anteriores (padrão 30).
 * - Aluno legado com NOTA já lançada no módulo tem o módulo sempre liberado.
 * - Dentro de um módulo liberado, o vídeo 1 é livre e os demais exigem o anterior assistido.
 *
 * Sem acesso a banco: recebe os dados prontos, o que permite testar as regras isoladamente.
 */
final class TrilhaService
{
    public const DURACAO_DIAS_PADRAO = 30;
    public const STATUS_APROVADO = 2;

    /**
     * @param list<array{id:int, duracaoDias:?int, videos:list<int>}> $modulos já ordenados; videos = IDs ordenados
     * @param array<int,true> $videosConcluidos  videoId => true (VIDEO_VIEW.STATUS = 2)
     * @param array<int,int>  $notas             disciplinaId => LISTA_STATUS_NOTA_ID
     * @param array<int,true> $modulosComProva   disciplinaId => true (PROVA ativa)
     * @return array<int, ModuloStatus> indexado pelo ID do módulo
     */
    public function calcular(
        array $modulos,
        ?\DateTimeImmutable $dataMatricula,
        array $videosConcluidos,
        array $notas,
        array $modulosComProva,
        \DateTimeImmutable $agora,
    ): array {
        $base = $dataMatricula ?? $agora;
        $dias = 0;
        $anteriorOk = true;
        $out = [];

        foreach ($modulos as $m) {
            $id = $m['id'];
            $s = new ModuloStatus($id);

            $s->conteudoConcluido = $this->todosAssistidos($m['videos'], $videosConcluidos);
            $s->provaOk = !isset($modulosComProva[$id]) || ($notas[$id] ?? null) === self::STATUS_APROVADO;
            $s->concluido = $s->conteudoConcluido && $s->provaOk;

            $s->dataLiberacaoPrevista = $base->modify("+{$dias} days");
            $s->tempoLiberado = $agora >= $s->dataLiberacaoPrevista;
            $s->liberadoLegado = array_key_exists($id, $notas);
            $s->liberado = $s->liberadoLegado || ($anteriorOk && $s->tempoLiberado);

            $s->videosLiberados = $this->videosLiberados($m['videos'], $s->liberado, $videosConcluidos);

            $out[$id] = $s;
            $anteriorOk = $s->liberado && $s->concluido;
            $dias += $m['duracaoDias'] ?? self::DURACAO_DIAS_PADRAO;
        }
        return $out;
    }

    /** @return array<int,bool> videoId => liberado */
    private function videosLiberados(array $videos, bool $moduloLiberado, array $concluidos): array
    {
        $out = [];
        $anterior = null;
        foreach ($videos as $i => $videoId) {
            $out[$videoId] = $moduloLiberado && ($i === 0 || isset($concluidos[$anterior]));
            $anterior = $videoId;
        }
        return $out;
    }

    private function todosAssistidos(array $videos, array $concluidos): bool
    {
        foreach ($videos as $v) {
            if (!isset($concluidos[$v])) {
                return false;
            }
        }
        return true;
    }
}
