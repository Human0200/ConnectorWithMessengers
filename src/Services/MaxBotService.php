<?php

declare(strict_types=1);

namespace BitrixTelegram\Services;

use BitrixTelegram\Helpers\Logger;

/**
 * Инкапсулирует прямые HTTP-вызовы к Max Bot API.
 * Используется контроллером вместо inline cURL.
 *
 * Отличие от MaxService: MaxService работает через токен домена из БД,
 * а MaxBotService работает с токеном профиля (из user_messenger_profiles.token),
 * который приходит напрямую в вебхук (?max_token=...).
 */
class MaxBotService
{
    private const API_URL = 'https://platform-api.max.ru';

    public function __construct(
        private Logger $logger
    ) {}

    /**
     * Отправить текстовое сообщение пользователю по его user_id.
     */
    public function sendMessage(string $token, string $userId, string $text): array
    {
        $url = self::API_URL . '/messages?user_id=' . urlencode($userId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . trim($token),
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logger->error('MaxBotService: cURL error', ['error' => $curlError]);
            return ['success' => false, 'error' => $curlError];
        }

        $result = ['success' => $httpCode === 200, 'response' => json_decode($response, true)];

        if (!$result['success']) {
            $this->logger->error('MaxBotService: API error', [
                'http_code' => $httpCode,
                'response'  => $result['response'],
            ]);
        }

        return $result;
    }
}