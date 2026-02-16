<?php
// session_worker.php - Асинхронный воркер на основе EventHandler

require_once __DIR__ . '/../../vendor/autoload.php';

use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler;
use danog\MadelineProto\EventHandler\Message;

$sessionPath = $argv[1] ?? '';
$sessionName = $argv[2] ?? 'unknown';
$sessionId   = $argv[3] ?? 0;
$accountName = $argv[4] ?? '';

if (empty($sessionPath) || !file_exists($sessionPath)) {
    fwrite(STDERR, "❌ Сессия не найдена\n");
    exit(1);
}

$GLOBALS['sessionName'] = $sessionName;
$GLOBALS['accountName'] = $accountName;

function colorize($text, $color = 'white') {
    if (!posix_isatty(STDOUT)) return $text;
    $colors = [
        'red' => "\033[31m", 'green' => "\033[32m", 'yellow' => "\033[33m",
        'blue' => "\033[34m", 'magenta' => "\033[35m", 'cyan' => "\033[36m",
        'white' => "\033[37m", 'gray' => "\033[90m", 'reset' => "\033[0m",
    ];
    return ($colors[$color] ?? $colors['white']) . $text . $colors['reset'];
}

function log_msg($msg, $color = 'white') {
    $timestamp = date('[H:i:s]');
    echo colorize("{$timestamp} [{$GLOBALS['sessionName']}] {$msg}\n", $color);
}

class TelegramEventHandler extends EventHandler
{
    public function onStart(): void
    {
        $self = $this->getSelf();
        $userId = $self['id'] ?? 'unknown';
        log_msg("✅ Авторизован как User ID: {$userId}", 'green');
        log_msg("👤 Аккаунт: {$GLOBALS['accountName']}", 'cyan');
    }

    public function onMessage(Message $message): void
    {
        $chatId = $this->extractChatId($message->chatId);
        $text = $message->message ?? '';
        $isOutgoing = $message->out;
        $direction = $isOutgoing ? '→' : '←';
        $dirColor = $isOutgoing ? 'blue' : 'magenta';

        $timestamp = date('H:i:s');
        echo colorize("{$timestamp} ", 'cyan');
        echo colorize($direction, $dirColor) . " ";
        echo colorize($chatId, 'white');
        if ($text) {
            $displayText = strlen($text) > 60 ? substr($text, 0, 60) . '...' : $text;
            echo " " . colorize($displayText, 'gray');
        }
        echo "\n";

        $this->sendToWebhook($message->rawData);
    }

    private function extractChatId($peerId): string
    {
        if (is_array($peerId)) {
            $type = $peerId['_'] ?? 'unknown';
            return match ($type) {
                'peerUser' => 'user_' . ($peerId['user_id'] ?? ''),
                'peerChat' => 'chat_' . ($peerId['chat_id'] ?? ''),
                'peerChannel' => 'channel_' . ($peerId['channel_id'] ?? ''),
                default => $type
            };
        }
        return is_numeric($peerId) ? 'user_' . $peerId : (string)$peerId;
    }

    private function sendToWebhook(array $messageData): void
    {
        try {
            $webhookUrl = 'http://localhost:8912/webhook.php';
            $postData = [
                'session_name' => $GLOBALS['sessionName'],
                'message' => $messageData,
                'timestamp' => time(),
            ];

            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($postData, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {}
    }
}

try {
    log_msg("🚀 Запуск сессии ID: {$sessionId}", 'green');
    $api = new API($sessionPath);
    $api->start();
    $api->setEventHandler(TelegramEventHandler::class);
    $api->loop();
} catch (\Throwable $e) {
    log_msg("❌ Критическая ошибка: " . $e->getMessage(), 'red');
    exit(1);
}