<?php
// listen_sessions.php - Прослушивание всех сессий

require_once __DIR__ . '/../../vendor/autoload.php';

use danog\MadelineProto\API;
use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;

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
    $tokenRepository = new TokenRepository($pdo);
    
    echo colorize("✅ Сервисы инициализированы\n", 'green');
} catch (\Exception $e) {
    echo colorize("❌ Ошибка: " . $e->getMessage() . "\n", 'red');
    exit(1);
}

// Получаем все домены через запрос
$domainsQuery = $pdo->query("SELECT DISTINCT domain FROM madelineproto_sessions WHERE status = 'authorized'");
$domains = $domainsQuery->fetchAll(PDO::FETCH_COLUMN);

if (empty($domains)) {
    echo colorize("❌ Нет активных сессий\n", 'red');
    exit(1);
}

// Собираем все активные сессии
$sessionInstances = [];

foreach ($domains as $domain) {
    $sessions = $tokenRepository->getActiveMadelineProtoSessions($domain);

    foreach ($sessions as $session) {
        try {
            $sessionPath = $session['session_file'];
            
            if (!file_exists($sessionPath)) {
                echo colorize("⚠️  Файл сессии не найден: {$session['session_name']}\n", 'yellow');
                continue;
            }
            
            echo colorize("🔄 Загрузка сессии: {$session['session_name']}...\n", 'cyan');
            
            try {
                $tempClassName = 'TempHandler_' . md5($sessionPath);
                
                if (!class_exists($tempClassName)) {
                    eval("
                        class {$tempClassName} extends \\danog\\MadelineProto\\EventHandler {
                            public function getReportPeers() { return []; }
                        }
                    ");
                }
                
                $instance = new API($sessionPath);
                $instance->start();
                $instance->stop();
                
                echo colorize("✅ Сессия очищена от старого EventHandler\n", 'green');
                
            } catch (\Exception $e) {
                echo colorize("⚠️  Не удалось очистить EventHandler: " . $e->getMessage() . "\n", 'yellow');
            }
            
            $instance = new API($sessionPath);
            $instance->start();

            if ($instance) {
                $sessionInstances[] = [
                    'instance' => $instance,
                    'domain' => $domain,
                    'session_id' => $session['session_id'],
                    'session_name' => $session['session_name'],
                    'account_name' => $session['account_first_name'] . ' (@' . ($session['account_username'] ?? 'N/A') . ')',
                    'last_update_id' => 0
                ];
                echo colorize("✅ Сессия загружена: {$session['session_name']}\n", 'green');
            }
        } catch (\Exception $e) {
            echo colorize("⚠️  Ошибка сессии {$session['session_name']}: {$e->getMessage()}\n", 'yellow');
        }
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

// Функция для извлечения chat_id
function extractChatId($peer)
{
    if (is_array($peer)) {
        $type = $peer['_'] ?? 'unknown';
        return match ($type) {
            'peerUser' => 'user_' . ($peer['user_id'] ?? ''),
            'peerChat' => 'chat_' . ($peer['chat_id'] ?? ''),
            'peerChannel' => 'channel_' . ($peer['channel_id'] ?? ''),
            default => $type
        };
    }
    return 'user_' . $peer;
}

// Функция для получения информации об отправителе
function getSenderInfo($madelineProto, $from_id)
{
    try {
        // Получаем информацию о пользователе
        $userInfo = $madelineProto->getFullInfo($from_id);
        
        // Извлекаем имя
        $firstName = $userInfo['User']['first_name'] ?? '';
        $lastName = $userInfo['User']['last_name'] ?? '';
        $username = $userInfo['User']['username'] ?? '';
        
        // Формируем полное имя
        $fullName = trim($firstName . ' ' . $lastName);
        
        // Добавляем username если есть
        if ($username) {
            $fullName .= " (@$username)";
        }
        
        return $fullName;
        
    } catch (\Exception $e) {
        // В случае ошибки возвращаем ID
        return "Пользователь $from_id";
    }
}

// Функция отправки в webhook
function sendToWebhook($sessionData, $message, $senderName = null)
{
    try {
        $webhookUrl = 'http://localhost:8912/webhook.php';

        $postData = [
            'session_name' => $sessionData['session_name'],
            'session_id' => $sessionData['session_id'],
            'domain' => $sessionData['domain'],
            'account_name' => $sessionData['account_name'],
            'message' => $message, // Весь массив сообщения
            'sender_name' => $senderName, // Добавляем имя отправителя
            'timestamp' => time(),
        ];

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
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

// Основной цикл
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
                        print_r($update);
                        $innerUpdate = $update['update'];
                        $updateType = $innerUpdate['_'] ?? 'unknown';

                        if ($updateType === 'updateNewMessage' || $updateType === 'updateNewChannelMessage') {
                            if (isset($innerUpdate['message'])) {
                                $message = $innerUpdate['message'];

                                $chatId = extractChatId($message['peer_id'] ?? null);
                                $text = $message['message'] ?? '';
                                $isOutgoing = !empty($message['out']);
                                $direction = $isOutgoing ? '→' : '←';
                                $dirColor = $isOutgoing ? 'blue' : 'magenta';

                                // Получаем имя отправителя
                                $senderName = null;
                                if (isset($message['from_id'])) {
                                    $senderName = getSenderInfo($s['instance'], $message['from_id']);
                                    
                                    // Выводим имя отправителя в консоль
                                    echo colorize(date('[H:i:s]'), 'cyan');
                                    echo " ";
                                    echo colorize("[{$s['session_name']}]", 'yellow');
                                    echo " ";
                                    echo colorize($direction, $dirColor);
                                    echo " ";
                                    echo colorize("От: $senderName", 'green'); // Добавляем отправителя
                                    echo " ";
                                    echo colorize("К: $chatId", 'white');

                                    if ($text) {
                                        $displayText = strlen($text) > 60 ? substr($text, 0, 60) . '...' : $text;
                                        echo " " . colorize($displayText, 'gray');
                                    }
                                    echo "\n";
                                } else {
                                    // Если нет from_id, выводим без имени
                                    echo colorize(date('[H:i:s]'), 'cyan');
                                    echo " ";
                                    echo colorize("[{$s['session_name']}]", 'yellow');
                                    echo " ";
                                    echo colorize($direction, $dirColor);
                                    echo " ";
                                    echo colorize($chatId, 'white');

                                    if ($text) {
                                        $displayText = strlen($text) > 60 ? substr($text, 0, 60) . '...' : $text;
                                        echo " " . colorize($displayText, 'gray');
                                    }
                                    echo "\n";
                                }

                                // Отправляем в webhook весь массив сообщения с именем отправителя
                                sendToWebhook($s, $message, $senderName);
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

    usleep(100000); // 0.1 сек
}