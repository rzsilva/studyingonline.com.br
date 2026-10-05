<?php

declare(strict_types=1);

namespace App\Domain\Trilha;

/**
 * Correção de prova NO SERVIDOR. No legado o navegador recebia o gabarito (CORRETA)
 * e enviava a nota pronta, o que permitia ao aluno ver as respostas e forjar a nota.
 */
final class CorrecaoProva
{
    public const OPCOES = ['A', 'B', 'C', 'D', 'E'];

    /**
     * @param list<array{ID:int|string, CORRETA:?string, VALOR:mixed}> $questoes
     * @param array<int|string,string> $respostas questaoId => 'A'..'E' (questões sem resposta valem 0)
     * @return array{total:float, itens:array<int, array{resposta:?string, acertou:bool, nota:float}>}
     */
    public function corrigir(array $questoes, array $respostas): array
    {
        $total = 0.0;
        $itens = [];
        foreach ($questoes as $q) {
            $id = (int) $q['ID'];
            $resp = isset($respostas[$id]) ? strtoupper(trim((string) $respostas[$id])) : null;
            if ($resp !== null && !in_array($resp, self::OPCOES, true)) {
                $resp = null;
            }
            $acertou = $resp !== null && $resp === strtoupper(trim((string) $q['CORRETA']));
            $nota = $acertou ? round((float) $q['VALOR'], 2) : 0.0;
            $total += $nota;
            $itens[$id] = ['resposta' => $resp, 'acertou' => $acertou, 'nota' => $nota];
        }
        return ['total' => round($total, 2), 'itens' => $itens];
    }

    /** Status da NOTA: 2 = aprovado, 3 = reprovado. Média nula = 0 (legado reprovava todos). */
    public function status(float $nota, ?float $media): int
    {
        return $nota >= ($media ?? 0.0) ? 2 : 3;
    }
}
