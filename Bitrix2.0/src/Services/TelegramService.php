<?php

declare(strict_types=1);

namespace BitrixTelegram\Services;

use BitrixTelegram\Helpers\Logger;

class TelegramService
{
    private string $botToken;
    private string $apiUrl;
    private Logger $logger;

    public function __construct(array $config, Logger $logger)
    {
        $this->botToken = $config['bot_token'];
        $this->apiUrl = $config['api_url'] . $this->botToken;
        $this->logger = $logger;
    }

    public function sendMessage(
        int $chatId,
        string $text,
        string $parseMode = 'HTML'
    ): array {
        return $this->makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ]);
    }

    public function sendPhoto(
        int $chatId,
        string $photoUrl,
        ?string $caption = null
    ): array {
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

    public function sendDocument(
        int $chatId,
        string $documentUrl,
        ?string $caption = null
    ): array {
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

    public function sendVoice(int $chatId, string $voiceUrl): array
    {
        return $this->makeRequest('sendVoice', [
            'chat_id' => $chatId,
            'voice' => $voiceUrl,
        ]);
    }

    public function sendVideo(
        int $chatId,
        string $videoUrl,
        ?string $caption = null
    ): array {
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

    public function deleteWebhook(): array
    {
        return $this->makeRequest('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return $this->makeRequest('getWebhookInfo');
    }

    public function getBotInfo(): array
    {
        return $this->makeRequest('getMe');
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
            $this->logger->error('Telegram API curl error', [
                'method' => $method,
                'error' => $error,
            ]);
            return ['ok' => false, 'error' => $error];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200 || !$result['ok']) {
            $this->logger->error('Telegram API error', [
                'method' => $method,
                'http_code' => $httpCode,
                'response' => $response,
            ]);
        }
        
        return $result;
    }
}