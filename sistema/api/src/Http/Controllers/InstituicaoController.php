<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Instituicao\InstituicaoResolver;
use App\Support\ApiException;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InstituicaoController
{
    /** Tema padrão quando o host não corresponde a nenhuma instituição. */
    private const DEFAULT_THEME = [
        'id' => null,
        'nome' => 'Studying Online',
        'titulo' => 'Studying Online',
        'logo' => null,
        'corPrimaria' => '#3498db',
        'celular' => null,
    ];

    public function __construct(private readonly InstituicaoResolver $resolver)
    {
    }

    /** GET /instituicoes/by-host?host=escola.studyingonline.com.br (público) */
    public function byHost(Request $request, Response $response): Response
    {
        $host = (string) ($request->getQueryParams()['host'] ?? '');
        if ($host === '') {
            throw ApiException::validation(['host' => 'Campo obrigatório.']);
        }
        $inst = $this->resolver->resolve($host);
        if ($inst === null) {
            return Json::ok($response, self::DEFAULT_THEME);
        }
        return Json::ok($response, [
            'id' => (int) $inst['ID'],
            'nome' => $inst['FANTASIA'],
            'titulo' => $inst['TITULO'] ?: $inst['FANTASIA'],
            'logo' => $inst['LOGO'],
            'corPrimaria' => self::safeColor($inst['COR_PRIMARIA']),
            'celular' => $inst['CELULAR'],
        ]);
    }

    /** Evita injeção de CSS: só aceita #rgb/#rrggbb. */
    public static function safeColor(?string $color): string
    {
        return $color && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? $color : self::DEFAULT_THEME['corPrimaria'];
    }
}
