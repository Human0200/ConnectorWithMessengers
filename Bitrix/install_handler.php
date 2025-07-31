<?php
require_once './crest.php';
require_once './settings.php';

$inputData = json_decode(file_get_contents('php://input'), true) ?: [];
$data = array_merge($_REQUEST, $inputData);

if (empty($data['auth'])) {
    die(json_encode(['error' => 'Auth data required']));
}

try {
    $dbh = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // Основные данные для сохранения
    $hookData = [
        'domain' => $data['auth']['domain'],
        'member_id' => $data['auth']['member_id'],
        'refresh_token' => $data['auth']['refresh_token'],
        'access_token' => $data['auth']['access_token'], 
        'client_id' => defined('C_REST_CLIENT_ID') ? C_REST_CLIENT_ID : '',
        'client_secret' => defined('C_REST_CLIENT_SECRET') ? C_REST_CLIENT_SECRET : '',
        'expires' => $data['auth']['expires'] 
    ];


    $requiredFields = ['domain', 'member_id', 'refresh_token', 'access_token'];
    foreach ($requiredFields as $field) {
        if (empty($hookData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

  
    $hookToken = rtrim(strtr(base64_encode(json_encode($hookData)), '+/', '-_'), '=');
    
    
    $stmt = $dbh->prepare("
        INSERT INTO `bitrix_integration_tokens` 
        (`domain`, `member_id`, `refresh_token`, `access_token`, `client_id`, `client_secret`, `hook_token`, `token_expires`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            `refresh_token` = VALUES(`refresh_token`),
            `access_token` = VALUES(`access_token`),
            `hook_token` = VALUES(`hook_token`),
            `token_expires` = VALUES(`token_expires`)
    ");
    
    $stmt->execute([
        $hookData['domain'],
        $hookData['member_id'],
        $hookData['refresh_token'],
        $hookData['access_token'],
        $hookData['client_id'],
        $hookData['client_secret'],
        $hookToken,
        $hookData['expires']
    ]);

    
    $result = CRest::call('app.option.set', ['options' => ['token' => $hookToken]]);
    
    
    echo json_encode([
        'status' => 'success',
        'token' => $hookToken,
        'db_affected_rows' => $stmt->rowCount(),
        'expires_in' => $hookData['expires'] - time()
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}