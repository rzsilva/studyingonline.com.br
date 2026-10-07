<?php

declare(strict_types=1);

/**
 * Rotina diária pela linha de comando (alternativa ao POST /rotinas/diaria):
 *   php bin/rotinas.php
 */

use App\Http\Controllers\RotinasController;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$res = $app->getContainer()->get(RotinasController::class)->executar();
echo json_encode($res, JSON_PRETTY_PRINT), PHP_EOL;
