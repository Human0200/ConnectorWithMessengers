<?php
require_once('./functions.php');
require_once('./service_api.php'); // Ваш файл с логикой бота
require_once('./settings.php');
require_once('./crest.php');

$input = file_get_contents('php://input');
file_put_contents(__DIR__ . '/handler.txt', $input, FILE_APPEND);
$data = json_decode($input, true);
$connector_id = getConnectorIDForApiToken($data['api_token_max']) ?: getConnectorID($_REQUEST['DOMAIN']);
file_put_contents(__DIR__ . '/connector_id.txt', $connector_id, FILE_APPEND);

if (empty($connector_id)) {
    die(json_encode(['error' => 'Connector ID not found']));
}

// --- 1. Активация коннектора в Битрикс24 ---
if (!empty($_REQUEST['PLACEMENT_OPTIONS']) && $_REQUEST['PLACEMENT'] == 'SETTING_CONNECTOR') {
    $options = json_decode($_REQUEST['PLACEMENT_OPTIONS'], true);

    $result = CRest::call(
        'imconnector.activate',
        [
            'CONNECTOR' => $connector_id,
            'LINE' => intVal($options['LINE']),
            'ACTIVE' => intVal($options['ACTIVE_STATUS']),
        ]
    );

    if (!empty($result['result'])) {
        setLine($options['LINE']);
echo '
<style>
    .success-card {
        max-width: 500px;
        margin: 20px auto;
        padding: 20px;
        border-radius: 12px;
        background: #f8f9ff;
        box-shadow: 0 4px 12px rgba(9, 82, 201, 0.15);
        border-left: 6px solid #0952C9;
        font-family: "Segoe UI", Arial, sans-serif;
        color: #333;
    }
    .success-card h3 {
        margin: 0 0 15px 0;
        color: #0952C9;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .success-card .info {
        margin: 5px 0;
        line-height: 1.6;
    }
    .success-card .info strong {
        color: #000;
        width: 180px;
        display: inline-block;
    }
    .icon {
        color: #0952C9;
    }
</style>

<div class="success-card">
    <h3><span class="icon">✅</span> Успешно!</h3>
    <div class="info"><strong>ID LINE:</strong> ' . htmlspecialchars($options['LINE']) . '</div>
    <div class="info"><strong>CONNECTOR:</strong> ' . htmlspecialchars($options['CONNECTOR']) . '</div>
    <div style="margin-top: 15px; font-size: 0.9em; color: #555;">
        <span class="icon">💡</span> Подключение активно и готово к использованию.
    </div>
</div>
';
        
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        $stmt = $pdo->prepare("UPDATE bitrix_integration_tokens SET id_openline = ? WHERE connector_id = ?");
        $stmt->execute([$options['LINE'], $connector_id]);
    }else{
        echo 'Ошибка: ';
        echo print_r($result, true);
    }
}

// --- 2. Прием сообщений ИЗ Битрикс24 (от оператора) ---
if (
    $_REQUEST['event'] == 'ONIMCONNECTORMESSAGEADD'
    && $_REQUEST['data']['CONNECTOR'] == $connector_id
    && !empty($_REQUEST['data']['MESSAGES'])
) {
    foreach ($_REQUEST['data']['MESSAGES'] as $message) {
        $chat_id = $message['chat']['id']; // ID чата в Telegram (например, сохраненный ранее)
        $text = $message['message']['text'];

        // Отправляем сообщение
        sendServiceMessage($chat_id, $text);

        // Подтверждаем доставку
        CRest::call(
            'imconnector.send.status.delivery',
            [
                'CONNECTOR' => $connector_id,
                'LINE' => getLineFromApiToken($data['api_token_max']),
                'MESSAGES' => [
                    [
                        'chat' => ['id' => $chat_id],
                        'message' => ['id' => [$message['message']['id']]]
                    ]
                ]
            ]
        );
    }
}

// --- 3. Вебхук для приема сообщений ИЗ Telegram ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);


    if (!empty($input['message']['text'])) {
        $chat_id = $input['message']['chat']['id'];
        $user_name = $input['message']['from']['first_name'];
        $text = $input['message']['text'];

        // Отправляем сообщение в Битрикс24
        $result = CRest::call(
            'imconnector.send.messages',
            [
                'CONNECTOR' => $connector_id,
                'LINE' => getLineFromConnectorID($connector_id),
                'MESSAGES' => [
                    [
                        'user' => [
                            'id' => $chat_id,
                            'name' => $user_name
                        ],
                        'message' => [
                            'text' => $text,
                            'date' => time()
                        ],
                        'chat' => [
                            'id' => 'max_' . $chat_id 
                        ]
                    ]
                ]
            ]
        );

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }
}
