<?php

declare(strict_types=1);

use App\Domain\Auth\AuthService;
use App\Domain\Auth\PasswordHasher;
use App\Domain\Auth\PasswordResetService;
use App\Domain\Auth\RefreshTokenRepository;
use App\Domain\Auth\TokenService;
use App\Domain\Instituicao\InstituicaoResolver;
use App\Domain\Secretaria\InscricaoService;
use App\Domain\Financeiro\CobrancaService;
use App\Domain\Adaline\AdalineService;
use App\Support\Segredo;
use App\Integrations\Pagamento\BoletoCloudGateway;
use App\Integrations\Pagamento\HttpCliente;
use App\Integrations\Pagamento\MercadoPagoGateway;
use App\Integrations\Pagamento\PagSeguroGateway;
use App\Integrations\Pagamento\VindiGateway;
use App\Domain\Instituicao\InstituicaoRepository;
use App\Domain\Usuario\UsuarioRepository;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RotinasController;
use App\Integrations\Mail\BrevoMailer;
use App\Integrations\Mail\Mailer;
use App\Integrations\Storage\LocalStorage;
use App\Support\Connection;
use App\Support\RateLimiter;
use Monolog\Handler\ErrorLogHandler;
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
        $logs = $c->get('settings')['storage_path'] . '/logs';
        // Sem permissão de escrita em storage/logs, usa o log de erros do PHP/IIS.
        $logger->pushHandler(is_writable($logs)
            ? new RotatingFileHandler($logs . '/api.log', 30)
            : new ErrorLogHandler());
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
        $c->get('settings')['env'] !== 'production' ? $c->get('settings')['storage_path'] . '/mail' : null,
    ),

    PasswordResetService::class => autowire()->constructorParameter('appUrl', static fn (C $c) => $c->get('settings')['app_url']),

    InscricaoService::class => autowire()
        ->constructorParameter('tokenSecret', static fn (C $c) => $c->get('settings')['auth']['jwt_secret'])
        ->constructorParameter('appUrl', static fn (C $c) => $c->get('settings')['app_url'])
        ->constructorParameter('clearLegacyPlaintext', static fn (C $c) => $c->get('settings')['auth']['legacy_clear_plaintext']),

    // Fase 5 — gateways de pagamento (URLs/credenciais globais no .env; credenciais por instituição em CONTA_BANCARIA)
    BoletoCloudGateway::class => static fn (C $c) => new BoletoCloudGateway(new HttpCliente(),
        $c->get('settings')['pagamentos']['boletocloud_proxy'], $c->get('settings')['pagamentos']['boletocloud_token']),
    VindiGateway::class => static fn (C $c) => new VindiGateway(new HttpCliente(), $c->get('settings')['pagamentos']['gateway_proxy']),
    PagSeguroGateway::class => static fn (C $c) => new PagSeguroGateway(new HttpCliente(), $c->get('settings')['pagamentos']['gateway_proxy']),
    MercadoPagoGateway::class => static fn (C $c) => new MercadoPagoGateway(new HttpCliente(),
        $c->get('settings')['pagamentos']['mercadopago_api'], $c->get('settings')['pagamentos']['api_publica'] . '/webhooks/mercadopago'),
    CobrancaService::class => autowire()
        ->constructorParameter('webhookSecret', static fn (C $c) => $c->get('settings')['auth']['jwt_secret']),

    AdalineService::class => autowire()
        ->constructorParameter('operadores', static fn (C $c) => array_values($c->get('settings')['adaline']['operadores']))
        ->constructorParameter('contaToken', static fn (C $c) => $c->get('settings')['adaline']['conta_token'])
        ->constructorParameter('tarifaBoleto', static fn (C $c) => $c->get('settings')['adaline']['tarifa_boleto']),

    Segredo::class => static fn (C $c) => new Segredo($c->get('settings')['segredos']['chave'], $c->get('settings')['segredos']['cifrar']),

    RotinasController::class => autowire()->constructorParameter('token', static fn (C $c) => $c->get('settings')['rotinas_token']),

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
