<?php

/**
 * user_api.php — REST API для пользовательского ЛК
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\UserRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Middleware\AuthMiddleware;
use BitrixTelegram\Controllers\AuthController;
use BitrixTelegram\Controllers\ProfileController;
use BitrixTelegram\Helpers\Logger;

$config = require __DIR__ . '/../config/config.php';
$pdo    = Database::getInstance($config['database'])->getConnection();
$logger = new Logger($config['logging']);

$userRepo    = new UserRepository($pdo);
$profileRepo = new ProfileRepository($pdo);
$tokenRepo   = new TokenRepository($pdo);
$authMidd    = new AuthMiddleware($userRepo);

$authCtrl    = new AuthController($userRepo, $authMidd, $logger);
$profileCtrl = new ProfileController($profileRepo, $tokenRepo, $authMidd, $logger);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = $_GET['_path'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = '/' . trim(preg_replace('#^/public/user_api\.php#', '', $path), '/');

$body = [];
$raw  = file_get_contents('php://input');
if ($raw) {
    $body = json_decode($raw, true) ?? $_POST;
} elseif ($_POST) {
    $body = $_POST;
}

try {
    $result = route($method, $path, $body, $authCtrl, $profileCtrl);
    http_response_code($result['_status'] ?? 200);
    unset($result['_status']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    $logger->logException($e, 'user_api unhandled');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

function route(
    string $method,
    string $path,
    array  $body,
    AuthController    $auth,
    ProfileController $profile
): array {
    // Сегменты: /auth/regen-token → ['auth', 'regen-token']
    //           /profiles/5/connect → ['profiles', '5', 'connect']
    $segments = array_values(array_filter(explode('/', $path), 'strlen'));

    $r0 = $segments[0] ?? '';
    $r1 = $segments[1] ?? null;
    $r2 = $segments[2] ?? null;

    // Числовой ID только если r1 — чисто цифровой
    $id  = ($r1 !== null && ctype_digit((string)$r1)) ? (int)$r1 : null;
    // Подресурс: если есть числовой ID — это r2, иначе r1
    $sub = ($id !== null) ? $r2 : $r1;

    // ── AUTH ─────────────────────────────────────────────────
    if ($r0 === 'auth') {
        return match (true) {
            $method === 'POST'  && $sub === 'register'    => $auth->register($body),
            $method === 'POST'  && $sub === 'login'       => $auth->login($body),
            $method === 'POST'  && $sub === 'logout'      => $auth->logout(),
            $method === 'GET'   && $sub === 'me'          => $auth->me(),
            $method === 'PATCH' && $sub === 'profile'     => $auth->updateProfile($body),
            $method === 'POST'  && $sub === 'regen-token' => $auth->regenToken(),
            default => ['success' => false, 'error' => 'Not found', '_status' => 404],
        };
    }

    // ── PROFILES ─────────────────────────────────────────────
    if ($r0 === 'profiles') {
        return match (true) {
            $method === 'GET'    && $id === null                          => $profile->index(),
            $method === 'POST'   && $id === null                          => $profile->create($body),
            $method === 'GET'    && $id !== null && $sub === null         => $profile->show($id),
            $method === 'PATCH'  && $id !== null && $sub === null         => $profile->update($id, $body),
            $method === 'DELETE' && $id !== null && $sub === null         => $profile->delete($id),
            $method === 'POST'   && $id !== null && $sub === 'connect'    => $profile->connect($id, $body),
            $method === 'DELETE' && $id !== null && $sub === 'connect'    => $profile->disconnect($id, $body),
            $method === 'PATCH'  && $id !== null && $sub === 'openline'   => $profile->setOpenline($id, $body),
            default => ['success' => false, 'error' => 'Not found', '_status' => 404],
        };
    }

    // ── DOMAINS ──────────────────────────────────────────────
    if ($r0 === 'domains' && $method === 'GET') {
        return $profile->getDomains();
    }

    return ['success' => false, 'error' => 'Not found', '_status' => 404];
}