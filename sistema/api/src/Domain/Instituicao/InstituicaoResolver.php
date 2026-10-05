<?php

declare(strict_types=1);

namespace App\Domain\Instituicao;

/**
 * Descobre a instituição pelo host do navegador (white-label), como o legado
 * LoginController.Index + api/Instituicao/GetByUrl: "escola.studyingonline.com.br"
 * ou domínio próprio cadastrado em INSTITUICAO.URL.
 */
final class InstituicaoResolver
{
    public function __construct(
        private readonly InstituicaoRepository $repo,
        private readonly string $baseDomain,
    ) {
    }

    public function resolve(string $host): ?array
    {
        $host = mb_strtolower(trim(explode(':', $host)[0]));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }
        foreach ($this->candidates($host) as $url) {
            if ($inst = $this->repo->findPublicByUrl($url)) {
                return $inst;
            }
        }
        return null;
    }

    /** @return string[] */
    private function candidates(string $host): array
    {
        $noWww = preg_replace('/^(www|sistema)\./', '', $host);
        $list = [$host, $noWww];
        if (!str_contains($host, '.')) {
            $list[] = "{$host}.{$this->baseDomain}";
        }
        return array_values(array_unique($list));
    }
}
