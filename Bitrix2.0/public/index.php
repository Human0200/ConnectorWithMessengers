<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Services\TokenService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Helpers\BBCodeConverter;
use BitrixTelegram\Helpers\MessageDetector;
use BitrixTelegram\Messengers\MessengerFactory;
use BitrixTelegram\Controllers\InstallController;
use BitrixTelegram\Controllers\WebhookController;

// Загружаем конфигурацию
$config = require __DIR__ . '/../config/config.php';

// Инициализируем зависимости
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();

$logger = new Logger($config['logging']);
$bbConverter = new BBCodeConverter();
$detector = new MessageDetector();

$tokenRepository = new TokenRepository($pdo);
$chatRepository = new ChatRepository($pdo);

$tokenService = new TokenService($tokenRepository, $logger, $config['bitrix']);
$bitrixService = new BitrixService($tokenRepository, $tokenService, $logger);

$messengerFactory = new MessengerFactory($config, $logger, $tokenRepository, $detector);

// Обработка запросов
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];

    if ($method === 'GET' && strpos($requestUri, '/install') !== false) {
        $installController = new InstallController(
            $bitrixService,
            $tokenRepository,
            $logger,
            $config
        );
        
        if (!empty($_REQUEST['PLACEMENT']) && $_REQUEST['PLACEMENT'] === 'DEFAULT') {
            $installController->install($_REQUEST);
        } else {
            echo '<h1>Bitrix Multi-Messenger Integration</h1>';
            echo '<p>Поддерживаемые мессенджеры: Telegram, Max</p>';
            echo '<p>Приложение готово к установке в Bitrix24</p>';
        }
    } elseif ($method === 'POST' && strpos($requestUri, '/webhook') !== false) {
        $webhookController = new WebhookController(
            $bitrixService,
            $messengerFactory,
            $tokenRepository,
            $chatRepository,
            $bbConverter,
            $logger,
            $detector
        );

        $input = file_get_contents('php://input');
        $data = json_decode($input, true) ?: [];
        
        // Объединяем POST данные и JSON данные
        $data = array_merge($_REQUEST, $data);

        // Логируем входящий запрос
        $logger->debug('Incoming webhook', [
            'method' => $method,
            'uri' => $requestUri,
            'data_keys' => array_keys($data),
        ]);

        $result = $webhookController->handleWebhook($data);

        header('Content-Type: application/json');
        echo json_encode($result);
    } elseif ($method === 'POST' && strpos($requestUri, '/activate') !== false) {
        $installController = new InstallController(
            $bitrixService,
            $tokenRepository,
            $logger,
            $config
        );
        
        $installController->activate($_REQUEST);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
} catch (\Exception $e) {
    $logger->logException($e, 'Request handling failed');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $config['app']['debug'] ? $e->getFile() : null,
        'line' => $config['app']['debug'] ? $e->getLine() : null,
    ]);
}