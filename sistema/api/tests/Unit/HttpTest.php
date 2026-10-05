<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\InstituicaoController;
use App\Http\Middleware\CorsMiddleware;
use App\Support\ApiException;
use App\Support\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HttpTest extends TestCase
{
    private function cors(string $origin): ResponseInterface
    {
        $mw = new CorsMiddleware(['http://localhost:5173', 'https://*.studyingonline.com.br'], new ResponseFactory());
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/me')->withHeader('Origin', $origin);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse();
            }
        };
        return $mw->process($req, $handler);
    }

    public function testCorsAllowsListedAndWildcardOrigins(): void
    {
        self::assertSame('http://localhost:5173', $this->cors('http://localhost:5173')->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(
            'https://escola.studyingonline.com.br',
            $this->cors('https://escola.studyingonline.com.br')->getHeaderLine('Access-Control-Allow-Origin')
        );
    }

    public function testCorsBlocksOtherOrigins(): void
    {
        self::assertSame('', $this->cors('https://evil.com')->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $this->cors('https://studyingonline.com.br.evil.com')->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $this->cors('https://a.b.studyingonline.com.br')->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testSafeColorBlocksCssInjection(): void
    {
        self::assertSame('#ff0000', InstituicaoController::safeColor('#ff0000'));
        self::assertSame('#3498db', InstituicaoController::safeColor('red;background:url(x)'));
        self::assertSame('#3498db', InstituicaoController::safeColor(null));
    }

    public function testValidatorCollectsFieldErrors(): void
    {
        try {
            Validator::make(['email' => 'nao-e-email'])->required('email', 'senha')->email('email')->validate();
            self::fail('esperava ApiException');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('email', $e->fields);
            self::assertArrayHasKey('senha', $e->fields);
        }
    }
}
