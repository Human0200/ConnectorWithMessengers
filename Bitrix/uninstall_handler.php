<?php
require_once './crest.php';
require_once './settings.php';

$inputData = json_decode(file_get_contents('php://input'), true) ?: [];
$data = array_merge($_REQUEST, $inputData);



if (empty($data['auth'])) {
    die(json_encode(['error' => 'Auth data required']));
}

$res = Crest::call(
    "imconnector.unregister",
    [
        "ID" => getConnectorID($data['auth']['domain'])
    ]
    );

// try {
//     $dbh = new PDO(
//         "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
//         DB_USER,
//         DB_PASS,
//         [
//             PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
//             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
//         ]
//     );

//     // Проверяем обязательные поля
//     $requiredFields = ['domain', 'member_id'];
//     foreach ($requiredFields as $field) {
//         if (empty($data['auth'][$field])) {
//             throw new Exception("Missing required field: $field");
//         }
//     }

//     // Удаляем запись из базы данных
//     $stmt = $dbh->prepare("
//         DELETE FROM `bitrix_integration_tokens` 
//         WHERE `domain` = ? AND `member_id` = ?
//     ");
    
//     $stmt->execute([
//         $data['auth']['domain'],
//         $data['auth']['member_id']
//     ]);

//     // Возвращаем успешный ответ
//     echo json_encode([
//         'status' => 'success',
//         'message' => 'Integration data removed successfully',
//         'db_affected_rows' => $stmt->rowCount()
//     ]);

// } catch (Exception $e) {
//     http_response_code(500);
//     echo json_encode(['error' => $e->getMessage()]);
// }