<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\ApiException;
use App\Support\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;

/** Converte qualquer exceção em JSON padronizado; detalhes internos só vão para o log. */
final class ErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $factory,
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(Request $request, \Throwable $e): Response
    {
        $response = $this->factory->createResponse();

        if ($e instanceof ApiException) {
            return Json::error($response, $e);
        }
        if ($e instanceof HttpException) {
            $code = $e->getCode() === 404 ? 'not_found' : ($e->getCode() === 405 ? 'method_not_allowed' : 'http_error');
            $msg = $e->getCode() === 404 ? 'Rota não encontrada.' : $e->getMessage();
            return Json::error($response, new ApiException($msg, $e->getCode(), $code));
        }

        $id = bin2hex(random_bytes(6));
        $this->logger->error($e->getMessage(), [
            'error_id' => $id,
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'route' => $request->getMethod() . ' ' . $request->getUri()->getPath(),
        ]);
        $msg = $this->debug ? $e->getMessage() : "Erro interno. Código: {$id}";
        return Json::error($response, new ApiException($msg, 500, 'internal_error'));
    }
}
