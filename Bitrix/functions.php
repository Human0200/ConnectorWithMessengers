<?php
include_once('./crest.php');
require_once('./settings.php');

// Подключение к БД
$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

/**
 * Получает или создает connector_id для домена
 */
function getConnectorID($domain)
{
    global $pdo;

    // Пробуем получить существующий
    $stmt = $pdo->prepare("SELECT connector_id FROM bitrix_integration_tokens WHERE domain = ?");
    $stmt->execute([$domain]);
    $connector_id = $stmt->fetchColumn();

    // Если нет - генерируем и сохраняем/обновляем
    if (!$connector_id) {
        $connector_id = 'max_' . bin2hex(random_bytes(8)); // 16-символьный ID

        $stmt = $pdo->prepare(
            "UPDATE bitrix_integration_tokens 
    SET connector_id = ?, last_updated = NOW() 
    WHERE domain = ?"
        );
        $stmt->execute([$connector_id, $domain]);

        // Если не обновилось ни одной строки - вставляем новую
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare(
                "INSERT INTO bitrix_integration_tokens 
        (domain, connector_id, last_updated) 
        VALUES (?, ?, NOW())"
            );
            $stmt->execute([$domain, $connector_id]);
        }
    }

    return $connector_id;
}
function getConnectorIDForApiToken($apitoken)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT connector_id FROM bitrix_integration_tokens WHERE api_token_max = ?");
    $stmt->execute([$apitoken]);
    $connector_id = $stmt->fetchColumn();
    return $connector_id;
}

/**
 * Простейшие заглушки
 */
function getLineFromConnectorID($connector_id)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT id_openline FROM bitrix_integration_tokens WHERE connector_id = ?");
    $stmt->execute([$connector_id]);
    $id_openline = $stmt->fetchColumn();
    return $id_openline;
}
function getLineFromApiToken($apitoken)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT id_openline FROM bitrix_integration_tokens WHERE api_token_max = ?");
    $stmt->execute([$apitoken]);
    $id_openline = $stmt->fetchColumn();
    return $id_openline;
}
function setLine($line_id)
{
    return true;
}
function saveMessage($chatID, $arMessage)
{
    return time();
}
function getChat($chatID)
{
    return [];
}

/**
 * Конвертер BB-кодов
 */
function convertBB($var)
{
    $replacements = [
        '/\[b\](.*?)\[\/b\]/is' => '<strong>$1</strong>',
        '/\[br\]/is' => '<br>',
        '/\[i\](.*?)\[\/i\]/is' => '<em>$1</em>',
        '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
        '/\[img\](.*?)\[\/img\]/is' => '<img src="$1" />',
        '/\[url\](.*?)\[\/url\]/is' => '<a href="$1">$1</a>',
        '/\[url\=(.*?)\](.*?)\[\/url\]/is' => '<a href="$1">$2</a>'
    ];
    return preg_replace(array_keys($replacements), array_values($replacements), $var);
}
