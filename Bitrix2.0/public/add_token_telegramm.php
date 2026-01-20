<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

// Загружаем конфигурацию
$config = require __DIR__ . '/../../config/config.php';

// Инициализируем зависимости
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();

$logger = new Logger($config['logging']);

try {
    // Проверка метода
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Только POST-запросы разрешены');
    }

    // Получение данных
    $input = json_decode(file_get_contents('php://input'), true);

    // Валидация
    if (!isset($input['domain'])) {
        throw new Exception('Параметр domain обязателен');
    }
    
    if (!isset($input['telegram_bot_token'])) {
        throw new Exception('Параметр telegram_bot_token обязателен');
    }

    $domain = trim($input['domain']);
    $telegramBotToken = trim($input['telegram_bot_token']);

    if (empty($domain)) {
        throw new Exception('Domain не может быть пустым');
    }
    
    if (empty($telegramBotToken)) {
        throw new Exception('Token не может быть пустым');
    }

    // Проверка существования записи
    $stmt = $pdo->prepare("SELECT 1 FROM bitrix_integration_tokens WHERE domain = ?");
    $stmt->execute([$domain]);
    $exists = $stmt->fetch();

    // Сохранение данных
    if ($exists) {
        $stmt = $pdo->prepare("
            UPDATE bitrix_integration_tokens 
            SET telegram_bot_token = ?, last_updated = NOW() 
            WHERE domain = ?
        ");
        $stmt->execute([$telegramBotToken, $domain]);
        $action = 'updated';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO bitrix_integration_tokens 
            (domain, telegram_bot_token, date_created, last_updated) 
            VALUES (?, ?, NOW(), NOW())
        ");
        $stmt->execute([$domain, $telegramBotToken]);
        $action = 'created';
    }

    $logger->info('Telegram token saved', [
        'domain' => $domain,
        'action' => $action,
    ]);

    // Успешный ответ
    echo json_encode([
        'success' => true,
        'action' => $action,
        'domain' => $domain,
    ]);

} catch (Exception $e) {
    $logger->logException($e, 'Add telegram token failed');
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}