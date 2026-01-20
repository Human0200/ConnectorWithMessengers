<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Services\TokenService;
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
$tokenService = new TokenService($tokenRepository, $logger, $config['bitrix']);
$bitrixService = new BitrixService($tokenRepository, $tokenService, $logger);

// Логируем запрос
$logger->info('Uninstall handler called', [
    'has_auth' => !empty($data['auth']),
    'domain' => $data['auth']['domain'] ?? null,
]);

if (empty($data['auth'])) {
    $logger->error('Uninstall handler: Auth data missing');
    http_response_code(400);
    echo json_encode(['error' => 'Auth data required']);
    exit;
}

try {
    $domain = $data['auth']['domain'];
    
    // Получаем connector_id перед удалением
    $connectorId = $tokenRepository->getConnectorId($domain);
    
    if ($connectorId) {
        // Отменяем регистрацию коннектора в Bitrix24
        $logger->info('Unregistering connector', [
            'domain' => $domain,
            'connector_id' => $connectorId,
        ]);
        
        $result = $bitrixService->unregisterConnector($connectorId, $domain);
        
        if (!empty($result['result'])) {
            $logger->info('Connector unregistered', [
                'domain' => $domain,
                'connector_id' => $connectorId,
            ]);
        } else {
            $logger->warning('Failed to unregister connector', [
                'domain' => $domain,
                'connector_id' => $connectorId,
                'error' => $result['error'] ?? 'unknown',
            ]);
        }
    }
    
    // Удаляем данные из БД (опционально - можно просто деактивировать)
    // В данном случае мы НЕ удаляем, а деактивируем для возможности восстановления
    
    // Можно добавить метод в TokenRepository для деактивации
    // $tokenRepository->deactivate($domain);
    
    $logger->info('Application uninstalled', [
        'domain' => $domain,
    ]);
    
    // Возвращаем успешный ответ
    echo json_encode([
        'status' => 'success',
        'message' => 'Application uninstalled successfully',
        'domain' => $domain,
    ]);

} catch (Exception $e) {
    $logger->logException($e, 'Uninstall handler failed');
    
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'status' => 'error',
    ]);
}