<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'settings.php';

// Включение лога
file_put_contents('debug.txt', "\n[".date('Y-m-d H:i:s')."] Новый запрос\n", FILE_APPEND);

try {
    // 1. Проверка метода
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Только POST-запросы разрешены');
    }

    // 2. Получение данных
    $input = json_decode(file_get_contents('php://input'), true);
    file_put_contents('debug.txt', "Полученные данные: ".print_r($input, true)."\n", FILE_APPEND);

    // 3. Валидация
    if (!isset($input['domain'])) {
        throw new Exception('Параметр domain обязателен');
    }
    if (!isset($input['api_token_max'])) {
        throw new Exception('Параметр api_token_max обязателен');
    }

    $domain = trim($input['domain']);
    $api_token_max = trim($input['api_token_max']);

    if (empty($domain)) {
        throw new Exception('Domain не может быть пустым');
    }
    if (empty($api_token_max)) {
        throw new Exception('Token не может быть пустым');
    }

    // 4. Подключение к БД
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // 5. Проверка существования записи
    $stmt = $pdo->prepare("SELECT 1 FROM bitrix_integration_tokens WHERE domain = ?");
    $stmt->execute([$domain]);
    $exists = $stmt->fetch();

    // 6. Сохранение данных
    if ($exists) {
        $stmt = $pdo->prepare("
            UPDATE bitrix_integration_tokens 
            SET api_token_max = ?, last_updated = NOW() 
            WHERE domain = ?
        ");
        $stmt->execute([$api_token_max, $domain]);
        $action = 'updated';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO bitrix_integration_tokens 
            (domain, api_token_max, date_created, last_updated) 
            VALUES (?, ?, NOW(), NOW())
        ");
        $stmt->execute([$domain, $api_token_max]);
        $action = 'created';
    }

    // 7. Успешный ответ
    echo json_encode([
        'success' => true,
        'action' => $action,
        'domain' => $domain
    ]);

} catch (Exception $e) {
    file_put_contents('debug.txt', "Ошибка: ".$e->getMessage()."\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

file_put_contents('debug.txt', "[".date('Y-m-d H:i:s')."] Завершение\n", FILE_APPEND);