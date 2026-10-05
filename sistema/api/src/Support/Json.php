<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

final class Json
{
    public static function ok(Response $response, mixed $data, array $meta = [], int $status = 200): Response
    {
        $body = ['data' => $data];
        if ($meta) {
            $body['meta'] = $meta;
        }
        return self::write($response, $body, $status);
    }

    public static function noContent(Response $response): Response
    {
        return $response->withStatus(204);
    }

    public static function error(Response $response, ApiException $e): Response
    {
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->fields) {
            $error['fields'] = $e->fields;
        }
        return self::write($response, ['error' => $error], $e->status);
    }

    public static function write(Response $response, array $body, int $status): Response
    {
        $response->getBody()->write(json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
