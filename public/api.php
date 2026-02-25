<?php

/**
 * api.php — точка входа REST API управления токенами
 * Размести этот файл рядом с webhook.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\ApiTokenRepository;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Middleware\ApiTokenMiddleware;
use BitrixTelegram\Controllers\ApiController;
use BitrixTelegram\Helpers\Logger;

$config = require __DIR__ . '/../config/config.php';

$pdo    = Database::getInstance($config['database'])->getConnection();
$logger = new Logger($config['logging']);

$apiTokenRepo = new ApiTokenRepository($pdo);
$tokenRepo    = new TokenRepository($pdo);
$middleware   = new ApiTokenMiddleware($apiTokenRepo, $logger);
$controller   = new ApiController($apiTokenRepo, $tokenRepo, $middleware, $logger);

$method = $_SERVER['REQUEST_METHOD'];

// Поддержка ?_path= от веб-панели (когда нельзя использовать красивые URL)
// Приоритет: ?_path= → реальный путь URI
if (!empty($_GET['_path'])) {
    $path = '/' . ltrim($_GET['_path'], '/');
} else {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
}

try {
    $controller->handle($method, $path);
} catch (\Throwable $e) {
    $logger->logException($e, 'API unhandled exception');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal server error']);
}