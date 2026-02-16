<?php

declare(strict_types=1);

namespace BitrixTelegram\Messengers;

use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Repositories\TokenRepository;

class TelegramMessenger implements MessengerInterface
{
    private ?string $botToken;
    private string $apiUrl;
    private Logger $logger;
    private TokenRepository $tokenRepository;
    private ?string $currentDomain = null;

    public function __construct(?string $botToken, Logger $logger, TokenRepository $tokenRepository)
    {
        $this->botToken = $botToken;
        $this->apiUrl = $botToken ? 'https://api.telegram.org/bot' . $botToken : '';
        $this->logger = $logger;
        $this->tokenRepository = $tokenRepository;
    }
    public function setDomain(string $domain): void
    {
        $this->currentDomain = $domain;
        $this->logger->debug('Domain set for TelegramMessenger', ['domain' => $domain]);
    }

    /**
     * Получить текущий домен
     */
    public function getDomain(): ?string
    {
        return $this->currentDomain;
    }
    /**
     * Установить токен бота динамически (для работы с разными ботами)
     */
    public function setBotToken(string $botToken): void
    {
        $this->botToken = $botToken;
        $this->apiUrl = 'https://api.telegram.org/bot' . $botToken;
    }

    /**
     * Получить токен по chat_id из БД
     */
    private function getTokenForChat(string $chatId): ?string
    {
        // Если есть домен, ищем токен по домену
        if ($this->currentDomain) {
            $token = $this->tokenRepository->getTelegramToken($this->currentDomain);
            if ($token) {
                $this->setBotToken($token);
                return $token;
            }
        }

        // Если домена нет, ищем по chat_id
        $token = $this->tokenRepository->getTelegramTokenByChatId((int) $chatId);

        if ($token) {
            $this->setBotToken($token);
            return $token;
        }

        // Возвращаем дефолтный токен, если установлен
        return $this->botToken;
    }

    public function sendMessage(string $chatId, string $text): array
    {
        // Получаем токен для этого чата
        $this->getTokenForChat($chatId);

        return $this->makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null): array
    {
        // Получаем токен для этого чата
        $this->getTokenForChat($chatId);

        $data = [
            'chat_id' => $chatId,
            'photo' => $photoUrl,
        ];

        if ($caption !== null) {
            $data['caption'] = $caption;
            $data['parse_mode'] = 'HTML';
        }

        return $this->makeRequest('sendPhoto', $data);
    }

    public function sendDocument(string $chatId, string $documentUrl, ?string $caption = null): array
    {
        // Получаем токен для этого чата
        $this->getTokenForChat($chatId);

        $data = [
            'chat_id' => $chatId,
            'document' => $documentUrl,
        ];

        if ($caption !== null) {
            $data['caption'] = $caption;
            $data['parse_mode'] = 'HTML';
        }

        return $this->makeRequest('sendDocument', $data);
    }

    public function sendVoice(string $chatId, string $voiceUrl): array
    {
        return $this->makeRequest('sendVoice', [
            'chat_id' => $chatId,
            'voice' => $voiceUrl,
        ]);
    }

    public function sendVideo(string $chatId, string $videoUrl, ?string $caption = null): array
    {
        $data = [
            'chat_id' => $chatId,
            'video' => $videoUrl,
        ];

        if ($caption !== null) {
            $data['caption'] = $caption;
            $data['parse_mode'] = 'HTML';
        }

        return $this->makeRequest('sendVideo', $data);
    }

    public function getFile(string $fileId): ?array
    {
        $result = $this->makeRequest('getFile', ['file_id' => $fileId]);
        return $result['ok'] ? $result['result'] : null;
    }

    public function getFileUrl(string $filePath): string
    {
        return sprintf(
            'https://api.telegram.org/file/bot%s/%s',
            $this->botToken,
            $filePath
        );
    }

    public function setWebhook(string $webhookUrl): array
    {
        return $this->makeRequest('setWebhook', [
            'url' => $webhookUrl,
            'allowed_updates' => ['message'],
        ]);
    }

    public function getInfo(): array
    {
        return $this->makeRequest('getMe');
    }

    public function normalizeIncomingMessage(array $message): array
    {
        return [
            'chat_id' => (string) $message['chat']['id'],
            'user_id' => (string) $message['from']['id'],
            'user_name' => $message['from']['first_name'] ?? 'User',
            'text' => $message['text'] ?? $message['caption'] ?? '',
            'message_id' => (string) $message['message_id'],
            'timestamp' => $message['date'],
            'type' => $this->detectMessageType($message),
            'reply_to' => isset($message['reply_to_message']) ? [
                'message_id' => (string) $message['reply_to_message']['message_id'],
                'text' => $message['reply_to_message']['text'] ??
                    $message['reply_to_message']['caption'] ?? '',
                'user_name' => $message['reply_to_message']['from']['first_name'] ?? 'Unknown',
                'is_bot' => $message['reply_to_message']['from']['is_bot'] ?? false,
            ] : null,
            'files' => $this->extractFiles($message),
            'raw' => $message,
        ];
    }

    public function denormalizeOutgoingMessage(array $message): array
    {
        // Конвертируем универсальный формат обратно в Telegram
        $result = [
            'chat_id' => $message['chat_id'],
        ];

        if (!empty($message['text'])) {
            $result['text'] = $message['text'];
            $result['parse_mode'] = 'HTML';
        }

        return $result;
    }

    public function getType(): string
    {
        return 'telegram';
    }

    private function detectMessageType(array $message): string
    {
        if (isset($message['photo'])) return 'photo';
        if (isset($message['document'])) return 'document';
        if (isset($message['voice'])) return 'voice';
        if (isset($message['video'])) return 'video';
        if (isset($message['audio'])) return 'audio';
        if (isset($message['sticker'])) return 'sticker';
        if (isset($message['text'])) return 'text';

        return 'unknown';
    }

    private function extractFiles(array $message): array
    {
        $files = [];

        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            $files[] = [
                'type' => 'photo',
                'file_id' => $photo['file_id'],
                'mime_type' => 'image/jpeg',
            ];
        }

        if (isset($message['document'])) {
            $files[] = [
                'type' => 'document',
                'file_id' => $message['document']['file_id'],
                'file_name' => $message['document']['file_name'] ?? 'document',
                'mime_type' => $message['document']['mime_type'] ?? 'application/octet-stream',
            ];
        }

        if (isset($message['voice'])) {
            $files[] = [
                'type' => 'voice',
                'file_id' => $message['voice']['file_id'],
                'mime_type' => 'audio/ogg',
            ];
        }

        if (isset($message['video'])) {
            $files[] = [
                'type' => 'video',
                'file_id' => $message['video']['file_id'],
                'mime_type' => 'video/mp4',
            ];
        }

        return $files;
    }

    private function makeRequest(string $method, array $data = []): array
    {
        $url = $this->apiUrl . '/' . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error('Telegram API error', [
                'method' => $method,
                'error' => $error,
            ]);
            return ['ok' => false, 'error' => $error];
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200 || !$result['ok']) {
            $this->logger->error('Telegram API failed', [
                'method' => $method,
                'http_code' => $httpCode,
                'response' => $response,
            ]);
        }

        return $result;
    }
}
