<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

// Загружаем конфигурацию
$config = require __DIR__ . '/../config/config.php';

// Получаем данные из запроса
$inputData = json_decode(file_get_contents('php://input'), true) ?: [];
$data = array_merge($_REQUEST, $inputData);

// Инициализируем зависимости
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();

$logger = new Logger($config['logging']);
$tokenRepository = new TokenRepository($pdo);

// Логируем запрос
$logger->info('Install handler called', [
    'has_auth' => !empty($data['auth']),
    'domain' => $data['auth']['domain'] ?? null,
]);

if (empty($data['auth'])) {
    $logger->error('Install handler: Auth data missing');
    http_response_code(400);
    echo json_encode(['error' => 'Auth data required']);
    exit;
}

try {
    // Подготавливаем данные для сохранения
    $installData = [
        'domain' => $data['auth']['domain'],
        'member_id' => $data['auth']['member_id'],
        'refresh_token' => $data['auth']['refresh_token'],
        'access_token' => $data['auth']['access_token'],
        'client_id' => $config['bitrix']['client_id'],
        'client_secret' => $config['bitrix']['client_secret'],
        'expires' => $data['auth']['expires'] ?? time() + 3600,
    ];

    // Валидация обязательных полей
    $requiredFields = ['domain', 'member_id', 'refresh_token', 'access_token'];
    foreach ($requiredFields as $field) {
        if (empty($installData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Сохраняем данные установки
    $success = $tokenRepository->saveInstallData($installData);

    if ($success) {
        $logger->info('Installation data saved', [
            'domain' => $installData['domain'],
        ]);

        // Создаем hook_token для ответа
        $hookToken = rtrim(
            strtr(base64_encode(json_encode($installData)), '+/', '-_'),
            '='
        );

        // Возвращаем успешный ответ
        echo json_encode([
            'status' => 'success',
            'domain' => $installData['domain'],
            'hook_token' => $hookToken,
            'expires_in' => $installData['expires'] - time(),
        ]);
    } else {
        throw new Exception('Failed to save installation data');
    }

} catch (Exception $e) {
    $logger->logException($e, 'Install handler failed');
    
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'status' => 'error',
    ]);
}