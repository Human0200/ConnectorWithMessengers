<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);


require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Services\MaxService;
use BitrixTelegram\Services\TokenService;
use BitrixTelegram\Services\TelegramBotService;
use BitrixTelegram\Services\MaxBotService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Helpers\BBCodeConverter;
use BitrixTelegram\Helpers\MessageDetector;
use BitrixTelegram\Messengers\MessengerFactory;
use BitrixTelegram\Handlers\BitrixToMessengerHandler;
use BitrixTelegram\Handlers\TelegramBotIncomingHandler;
use BitrixTelegram\Handlers\MaxBotIncomingHandler;
use BitrixTelegram\Controllers\WebhookController;

// ─── Конфигурация ────────────────────────────────────────────────

$config = require __DIR__ . '/../config/config.php';

// ─── Инфраструктура ──────────────────────────────────────────────

$database = Database::getInstance($config['database']);
$pdo      = $database->getConnection();

$logger      = new Logger($config['logging']);
$bbConverter = new BBCodeConverter();
$detector    = new MessageDetector();

// ─── Репозитории ─────────────────────────────────────────────────

$tokenRepository   = new TokenRepository($pdo);
$chatRepository    = new ChatRepository($pdo);
$profileRepository = new ProfileRepository($pdo);

// ─── Сервисы ─────────────────────────────────────────────────────

$tokenService       = new TokenService($tokenRepository, $logger, $config['bitrix']);
$bitrixService      = new BitrixService($tokenRepository, $tokenService, $logger);
$maxService         = new MaxService($tokenRepository, $logger);
$telegramBotService = new TelegramBotService($logger);
$maxBotService      = new MaxBotService($logger);

// ─── Фабрика мессенджеров ─────────────────────────────────────────

$messengerFactory = new MessengerFactory($config, $logger, $tokenRepository, $detector);
$messengerFactory->setMaxService($maxService);

// ─── Хендлеры ────────────────────────────────────────────────────

$bitrixToMessengerHandler = new BitrixToMessengerHandler(
    $bitrixService,
    $telegramBotService,
    $messengerFactory,
    $tokenRepository,
    $chatRepository,
    $logger
);

$telegramBotIncomingHandler = new TelegramBotIncomingHandler(
    $bitrixService,
    $telegramBotService,
    $tokenRepository,
    $profileRepository,
    $chatRepository,
    $logger
);

$maxBotIncomingHandler = new MaxBotIncomingHandler(
    $bitrixService,
    $tokenRepository,
    $profileRepository,
    $chatRepository,
    $logger
);

// ─── Контроллер ──────────────────────────────────────────────────

$webhookController = new WebhookController(
    $bitrixToMessengerHandler,
    $telegramBotIncomingHandler,
    $maxBotIncomingHandler,
    $messengerFactory,
    $bitrixService,
    $telegramBotService,
    $tokenRepository,
    $profileRepository,
    $chatRepository,
    $logger,
    $detector
);

// ─── Обработка запроса ───────────────────────────────────────────

try {
    $input = file_get_contents('php://input');

    // Поддержка JSON и form-encoded
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $data = json_decode($input, true) ?? [];
    } else {
        $data = $_POST ?: [];
        if ($input) {
            $data = array_merge($data, json_decode($input, true) ?? []);
        }
    }

    // GET-параметры (bot_token, max_token и др.) мержим отдельно,
    // чтобы не перезаписать тело запроса
    $data = array_merge($data, $_GET);

    $logger->info('Webhook received', [
        'method'       => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'content_type' => $contentType,
        'has_data'     => !empty($data),
        'data_keys'    => array_keys($data),
    ]);

    $result = $webhookController->handleWebhook($data);

    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    $logger->logException($e, 'Webhook processing failed');

    $logger->error('Webhook error details', [
        'message'       => $e->getMessage(),
        'file'          => $e->getFile(),
        'line'          => $e->getLine(),
        'trace'         => $e->getTraceAsString(),
        'data_received' => !empty($input) ? $input : 'empty',
    ]);

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'error',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error',
        'file'    => $config['app']['debug'] ? $e->getFile() : null,
        'line'    => $config['app']['debug'] ? $e->getLine() : null,
    ], JSON_UNESCAPED_UNICODE);
}