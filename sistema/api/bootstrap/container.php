<?php

declare(strict_types=1);

use App\Domain\Auth\AuthService;
use App\Domain\Auth\PasswordHasher;
use App\Domain\Auth\PasswordResetService;
use App\Domain\Auth\RefreshTokenRepository;
use App\Domain\Auth\TokenService;
use App\Domain\Instituicao\InstituicaoResolver;
use App\Domain\Instituicao\InstituicaoRepository;
use App\Domain\Usuario\UsuarioRepository;
use App\Http\Controllers\AuthController;
use App\Integrations\Mail\BrevoMailer;
use App\Integrations\Mail\Mailer;
use App\Integrations\Storage\LocalStorage;
use App\Support\Connection;
use App\Support\RateLimiter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface as C;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

use function DI\autowire;
use function DI\get;

return [
    'settings' => require dirname(__DIR__) . '/config/settings.php',

    Connection::class => static fn (C $c) => new Connection($c->get('settings')['db']),

    LoggerInterface::class => static function (C $c) {
        $logger = new Logger('api');
        $logger->pushHandler(new RotatingFileHandler($c->get('settings')['storage_path'] . '/logs/api.log', 30));
        return $logger;
    },

    ResponseFactoryInterface::class => autowire(ResponseFactory::class),

    RateLimiter::class => static fn (C $c) => new RateLimiter($c->get('settings')['storage_path'] . '/ratelimit'),

    LocalStorage::class => static fn (C $c) => new LocalStorage($c->get('settings')['storage_path'] . '/uploads'),

    TokenService::class => static fn (C $c) => new TokenService(
        $c->get('settings')['auth']['jwt_secret'],
        $c->get('settings')['auth']['jwt_ttl'],
    ),

    AuthService::class => static fn (C $c) => new AuthService(
        $c->get(UsuarioRepository::class),
        $c->get(RefreshTokenRepository::class),
        $c->get(TokenService::class),
        $c->get(PasswordHasher::class),
        $c->get(RateLimiter::class),
        $c->get('settings')['auth']['refresh_ttl'],
        $c->get('settings')['auth']['legacy_clear_plaintext'],
    ),

    Mailer::class => static fn (C $c) => new BrevoMailer(
        $c->get('settings')['mail']['brevo_key'],
        $c->get('settings')['mail']['from'],
        $c->get('settings')['mail']['from_name'],
        $c->get(LoggerInterface::class),
    ),

    PasswordResetService::class => autowire()->constructorParameter('appUrl', static fn (C $c) => $c->get('settings')['app_url']),

    InstituicaoResolver::class => static fn (C $c) => new InstituicaoResolver(
        $c->get(InstituicaoRepository::class),
        $c->get('settings')['base_domain'],
    ),

    AuthController::class => static fn (C $c) => new AuthController(
        $c->get(AuthService::class),
        $c->get(PasswordResetService::class),
        $c->get(InstituicaoResolver::class),
        $c->get('settings')['auth']['refresh_ttl'],
        $c->get('settings')['auth']['cookie_secure'],
    ),
];
