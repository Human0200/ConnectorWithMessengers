<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Controllers\TokenController;
use BitrixTelegram\Helpers\Logger;

// Загружаем конфигурацию
$config = require __DIR__ . '/../config/config.php';

// Инициализируем зависимости
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();

$logger = new Logger($config['logging']);
$tokenRepository = new TokenRepository($pdo);
$tokenController = new TokenController($tokenRepository, $logger);
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

    if (!isset($input['api_token_max'])) {
        throw new Exception('Параметр api_token_max обязателен');
    }

    $domain = trim($input['domain']);
    $apiTokenMax = trim($input['api_token_max']);

    if (empty($domain)) {
        throw new Exception('Domain не может быть пустым');
    }

    if (empty($apiTokenMax)) {
        throw new Exception('Token не может быть пустым');
    }

    // Добавляем токен через контроллер
    $result = $tokenController->addMaxToken([
        'domain' => $domain,
        'api_token_max' => $apiTokenMax,
    ]);

    if ($result['success']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'action' => $result['action'],
            'domain' => $domain,
        ]);
    } else {
        throw new Exception($result['error'] ?? 'Неизвестная ошибка');
    }
} catch (Exception $e) {
    $logger->logException($e, 'Add token max failed');

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
