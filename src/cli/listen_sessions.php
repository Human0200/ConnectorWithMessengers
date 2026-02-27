<?php
// listen_sessions.php - Прослушивание всех сессий

require_once __DIR__ . '/../../vendor/autoload.php';

use danog\MadelineProto\API;
use BitrixTelegram\Database\Database;

$config = require __DIR__ . '/../../config/config.php';

// Цвета для консоли
function colorize($text, $color = 'white')
{
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'gray' => "\033[90m",
        'reset' => "\033[0m",
    ];
    return ($colors[$color] ?? $colors['white']) . $text . $colors['reset'];
}

echo "\n";
echo colorize("╔════════════════════════════════════════════════════════════════╗\n", 'cyan');
echo colorize("║              🎧 TELEGRAM MESSAGE LISTENER                     ║\n", 'cyan');
echo colorize("╚════════════════════════════════════════════════════════════════╝\n", 'cyan');
echo "\n";

// Инициализация
try {
    $database = Database::getInstance($config['database']);
    $pdo = $database->getConnection();

    echo colorize("✅ Сервисы инициализированы\n", 'green');
} catch (\Exception $e) {
    echo colorize("❌ Ошибка: " . $e->getMessage() . "\n", 'red');
    exit(1);
}

// Получаем все авторизованные сессии (новая архитектура: через profile_id, без domain)
$stmt = $pdo->query("
    SELECT
        ms.profile_id,
        ms.session_id,
        ms.session_file,
        ms.session_name,
        ms.account_first_name,
        ms.account_username
    FROM madelineproto_sessions ms
    JOIN user_messenger_profiles ump ON ump.id = ms.profile_id
    WHERE ms.status = 'authorized'
      AND ump.is_active = 1
      AND ump.messenger_type = 'telegram_user'
    ORDER BY ms.id
");
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($sessions)) {
    echo colorize("❌ Нет активных сессий\n", 'red');
    exit(1);
}

// Собираем все активные сессии
$sessionInstances = [];

foreach ($sessions as $session) {
    try {
        $sessionPath = $session['session_file'];

        // Разрешаем относительный путь
        if (!str_starts_with($sessionPath, '/')) {
            $sessionPath = __DIR__ . '/../../storage/sessions/' . basename($sessionPath);
        }

        if (!file_exists($sessionPath)) {
            echo colorize("⚠️  Файл сессии не найден: {$session['session_name']}\n", 'yellow');
            continue;
        }

        echo colorize("🔄 Загрузка сессии: {$session['session_name']}...\n", 'cyan');

        // Просто создаем экземпляр без попыток переопределения EventHandler через eval
        $instance = new API($sessionPath);
        $instance->start();

        if ($instance) {
            $sessionInstances[] = [
                'instance'       => $instance,
                'profile_id'     => $session['profile_id'],
                'session_id'     => $session['session_id'],
                'session_name'   => $session['session_name'],
                'account_name'   => trim(($session['account_first_name'] ?? '') . ' (@' . ($session['account_username'] ?? 'N/A') . ')'),
                'last_update_id' => 0,
            ];
            echo colorize("✅ Сессия загружена: {$session['session_name']}\n", 'green');
        }
    } catch (\Exception $e) {
        echo colorize("⚠️  Ошибка сессии {$session['session_name']}: {$e->getMessage()}\n", 'yellow');
    }
}

if (empty($sessionInstances)) {
    echo colorize("❌ Нет активных сессий\n", 'red');
    exit(1);
}

echo colorize("✅ Загружено сессий: " . count($sessionInstances) . "\n", 'green');

// Список сессий
foreach ($sessionInstances as $i => $s) {
    echo colorize("  " . ($i + 1) . ". ", 'yellow');
    echo colorize($s['session_name'], 'white');
    echo colorize(" ({$s['account_name']})\n", 'magenta');
}
echo "\n";

// Функция для извлечения chat_id (совместимость с PHP 7)
function extractChatId($peer)
{
    if (is_array($peer)) {
        $type = $peer['_'] ?? 'unknown';
        
        if ($type === 'peerUser') {
            return 'user_' . ($peer['user_id'] ?? '');
        } elseif ($type === 'peerChat') {
            return 'chat_' . ($peer['chat_id'] ?? '');
        } elseif ($type === 'peerChannel') {
            return 'channel_' . ($peer['channel_id'] ?? '');
        }
        
        return $type;
    }
    return 'user_' . $peer;
}

// Функция для получения информации об отправителе
function getSenderInfo($madelineProto, $from_id)
{
    try {
        $userInfo  = $madelineProto->getFullInfo($from_id);
        $firstName = $userInfo['User']['first_name'] ?? '';
        $lastName  = $userInfo['User']['last_name'] ?? '';
        $username  = $userInfo['User']['username'] ?? '';
        $fullName  = trim($firstName . ' ' . $lastName);
        
        if (!empty($username)) {
            $fullName .= " (@$username)";
        }
        
        return !empty($fullName) ? $fullName : "Пользователь $from_id";
    } catch (\Exception $e) {
        return "Пользователь $from_id";
    }
}

// Функция отправки в webhook
function sendToWebhook($sessionData, $message, $senderName = null)
{
    try {
        $webhookUrl = 'http://localhost:8912/public/webhook.php';

        $postData = [
            'profile_id'   => $sessionData['profile_id'],   // вместо domain
            'session_id'   => $sessionData['session_id'],
            'session_name' => $sessionData['session_name'],
            'account_name' => $sessionData['account_name'],
            'message'      => $message,
            'sender_name'  => $senderName,
            'timestamp'    => time(),
        ];

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo colorize("      ⚠️  Webhook ответил: {$httpCode}\n", 'gray');
        }
    } catch (\Exception $e) {
        echo colorize("      ⚠️  Ошибка webhook: {$e->getMessage()}\n", 'gray');
    }
}

// Пропускаем старые сообщения
echo colorize("🔄 Пропуск старых сообщений...\n", 'yellow');
foreach ($sessionInstances as &$s) {
    try {
        $updates = $s['instance']->getUpdates();
        if (!empty($updates)) {
            $last = end($updates);
            $s['last_update_id'] = $last['update_id'] ?? 0;
        }
    } catch (\Exception $e) {
        // Игнорируем
    }
}
echo colorize("✅ Готово!\n\n", 'green');

echo colorize("╔════════════════════════════════════════════════════════════════╗\n", 'green');
echo colorize("║  🎧 ПРОСЛУШИВАНИЕ ЗАПУЩЕНО (Ctrl+C для остановки)            ║\n", 'green');
echo colorize("╚════════════════════════════════════════════════════════════════╝\n", 'green');
echo "\n";

// Основной цикл с контролем памяти
$iteration = 0;
while (true) {
    foreach ($sessionInstances as &$s) {
        try {
            $params = ['timeout' => 0];
            if ($s['last_update_id'] > 0) {
                $params['offset'] = $s['last_update_id'] + 1;
            }

            $updates = $s['instance']->getUpdates($params);

            if (!empty($updates)) {
                
                foreach ($updates as $update) {
                    $updateId = $update['update_id'] ?? 0;
                    if ($updateId > $s['last_update_id']) {
                        $s['last_update_id'] = $updateId;
                    }

                    if (isset($update['update']) && is_array($update['update'])) {
                        $innerUpdate = $update['update'];
                        $updateType  = $innerUpdate['_'] ?? 'unknown';

                        if ($updateType === 'updateNewMessage' || $updateType === 'updateNewChannelMessage') {
                            if (isset($innerUpdate['message'])) {
                                $message    = $innerUpdate['message'];
                                $chatId     = extractChatId($message['peer_id'] ?? null);
                                $text       = $message['message'] ?? '';
                                $isOutgoing = !empty($message['out']);
                                $direction  = $isOutgoing ? '→' : '←';
                                $dirColor   = $isOutgoing ? 'blue' : 'magenta';

                                $senderName = null;
                                if (isset($message['from_id'])) {
                                    $senderName = getSenderInfo($s['instance'], $message['from_id']);

                                    echo colorize(date('[H:i:s]'), 'cyan');
                                    echo " ";
                                    echo colorize("[{$s['session_name']}]", 'yellow');
                                    echo " ";
                                    echo colorize($direction, $dirColor);
                                    echo " ";
                                    echo colorize("От: $senderName", 'green');
                                    echo " ";
                                    echo colorize("К: $chatId", 'white');

                                    if (!empty($text)) {
                                        $displayText = strlen($text) > 60 ? substr($text, 0, 60) . '...' : $text;
                                        echo " " . colorize($displayText, 'gray');
                                    }
                                    echo "\n";
                                    
                                    // Отправляем в webhook
                                    sendToWebhook($s, $message, $senderName);
                                } else {
                                    echo colorize(date('[H:i:s]'), 'cyan');
                                    echo " ";
                                    echo colorize("[{$s['session_name']}]", 'yellow');
                                    echo " ";
                                    echo colorize($direction, $dirColor);
                                    echo " ";
                                    echo colorize($chatId, 'white');

                                    if (!empty($text)) {
                                        $displayText = strlen($text) > 60 ? substr($text, 0, 60) . '...' : $text;
                                        echo " " . colorize($displayText, 'gray');
                                    }
                                    echo "\n";
                                    
                                    // Отправляем в webhook без senderName
                                    sendToWebhook($s, $message, null);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Timeout') === false) {
                echo colorize("⚠️  [{$s['session_name']}]: {$e->getMessage()}\n", 'yellow');
            }
        }
    }

    // Периодическая очистка памяти
    $iteration++;
    if ($iteration % 1000 === 0) {
        gc_collect_cycles();
    }

    usleep(100000); // 0.1 сек
}