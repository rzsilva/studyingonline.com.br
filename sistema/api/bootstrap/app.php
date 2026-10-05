<?php

declare(strict_types=1);

use App\Http\ErrorHandler;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\JsonBodyMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/container.php');
if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
    $builder->enableCompilation($root . '/storage/cache');
}
$container = $builder->build();
$settings = $container->get('settings');

AppFactory::setContainer($container);
$app = AppFactory::create();

// A API pode ficar em subpasta (ex.: /api no IIS da Locaweb). API_BASE_PATH força o valor (dev com php -S).
$basePath = $_ENV['API_BASE_PATH']
    ?? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($basePath !== '' && $basePath !== '.') {
    $app->setBasePath($basePath);
}

(require __DIR__ . '/routes.php')($app);

$app->add(new JsonBodyMiddleware());
$app->addBodyParsingMiddleware(); // form-urlencoded / multipart
$app->addRoutingMiddleware();
$errors = $app->addErrorMiddleware($settings['debug'], true, true, $container->get(LoggerInterface::class));
$errors->setDefaultErrorHandler(new ErrorHandler(
    $container->get(ResponseFactoryInterface::class),
    $container->get(LoggerInterface::class),
    $settings['debug'],
));
$app->add(new SecurityHeadersMiddleware($settings['auth']['cookie_secure']));
// CORS por último = executa primeiro, inclusive em respostas de erro e preflight
$app->add(new CorsMiddleware($settings['cors_origins'], $container->get(ResponseFactoryInterface::class)));

return $app;
