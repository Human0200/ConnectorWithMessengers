<?php
// webhook.php
require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Services\TokenService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Helpers\BBCodeConverter;
use BitrixTelegram\Helpers\MessageDetector;
use BitrixTelegram\Messengers\MessengerFactory;
use BitrixTelegram\Controllers\WebhookController;

$config = require __DIR__ . '/../config/config.php';

$database = Database::getInstance($config['database']);
$pdo      = $database->getConnection();

$logger      = new Logger($config['logging']);
$bbConverter = new BBCodeConverter();
$detector    = new MessageDetector();

$tokenRepository   = new TokenRepository($pdo);
$profileRepository = new ProfileRepository($pdo);
$chatRepository    = new ChatRepository($pdo);

$tokenService  = new TokenService($tokenRepository, $logger, $config['bitrix']);
$bitrixService = new BitrixService($tokenRepository, $tokenService, $logger);

$messengerFactory = new MessengerFactory(
    $config,
    $logger,
    $tokenRepository,
    $detector,
);

try {
    $input = file_get_contents('php://input');
    $data  = json_decode($input, true) ?: [];
    $data  = array_merge($_REQUEST, $data, $_GET);

    $logger->info('Webhook received', [
        'method'       => $_SERVER['REQUEST_METHOD'],
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'has_data'     => !empty($data),
        'data_keys'    => array_keys($data),
    ]);

    $webhookController = new WebhookController(
        $bitrixService,
        $messengerFactory,
        $tokenRepository,
        $profileRepository,
        $chatRepository,
        $bbConverter,
        $logger,
        $detector
    );

    $result = $webhookController->handleWebhook($data);

    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    $logger->logException($e, 'Webhook processing failed');
    $logger->error('Webhook error details', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => $config['app']['debug'] ? $e->getTraceAsString() : 'hidden',
        'data_received' => !empty($data) ? json_encode($data) : 'empty',
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