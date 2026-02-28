<?php

declare(strict_types=1);

namespace BitrixTelegram\Services;

use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Repositories\TokenRepository;

/**
 * Сервис для работы с Max API.
 *
 * Два режима авторизации:
 * 1. По домену: токен берётся из bitrix_integration_tokens.api_token_max
 * 2. Явный токен: методы *WithToken() — токен передаётся напрямую (из user_messenger_profiles)
 */
class MaxService
{
    private string $apiUrl;

    public function __construct(
        private TokenRepository $tokenRepository,
        private Logger $logger,
        string $apiUrl = 'https://platform-api.max.ru'
    ) {
        $this->apiUrl = $apiUrl;
    }

    // ─── Получение токена ─────────────────────────────────────────

    private function getTokenForDomain(string $domain): ?string
    {
        $tokenData = $this->tokenRepository->findByDomain($domain);
        return $tokenData['api_token_max'] ?? null;
    }

    // ─── Отправка сообщений (по домену) ──────────────────────────

    public function sendMessage(string $userId, string $text, string $domain): array
    {
        return $this->makeRequest("messages?user_id={$userId}", ['text' => $text], $domain);
    }

    public function sendImage(string $userId, string $imageUrl, ?string $caption, string $domain): array
    {
        return $this->makeRequest("messages?user_id={$userId}", [
            'text'        => $caption ?? '',
            'attachments' => [['type' => 'image', 'payload' => ['url' => $imageUrl]]],
        ], $domain);
    }

    public function sendFile(string $userId, string $filePath, ?string $caption, string $domain, ?string $originalName = null): array
    {
        $token = $this->uploadFile($filePath, 'file', $domain, $originalName);
        if (!$token) {
            return ['success' => false, 'error' => 'Failed to upload file'];
        }
        sleep(2);
        return $this->sendAttachmentWithRetry($userId, 'file', $token, $caption, $domain);
    }

    public function sendAudio(string $userId, string $audioPath, string $domain): array
    {
        $token = $this->uploadFile($audioPath, 'audio', $domain);
        if (!$token) {
            return ['success' => false, 'error' => 'Failed to upload audio'];
        }
        sleep(2);
        return $this->sendAttachmentWithRetry($userId, 'audio', $token, null, $domain);
    }

    public function sendVideo(string $userId, string $videoPath, ?string $caption, string $domain): array
    {
        $token = $this->uploadFile($videoPath, 'video', $domain);
        if (!$token) {
            return ['success' => false, 'error' => 'Failed to upload video'];
        }
        sleep(2);
        return $this->sendAttachmentWithRetry($userId, 'video', $token, $caption, $domain);
    }

    // ─── Отправка сообщений (явный токен) ────────────────────────

    public function sendMessageWithToken(string $userId, string $text, string $token): array
    {
        return $this->makeRequestWithToken("messages?user_id={$userId}", ['text' => $text], $token);
    }

    public function sendImageWithToken(string $userId, string $imageUrl, ?string $caption, string $token): array
    {
        return $this->makeRequestWithToken("messages?user_id={$userId}", [
            'text'        => $caption ?? '',
            'attachments' => [['type' => 'image', 'payload' => ['url' => $imageUrl]]],
        ], $token);
    }

    public function sendFileWithToken(string $userId, string $filePath, ?string $caption, string $token, ?string $originalName = null): array
    {
        $uploadToken = $this->uploadFileWithToken($filePath, 'file', $token, $originalName);
        if (!$uploadToken) {
            return ['success' => false, 'error' => 'Failed to upload file'];
        }
        sleep(2);
        return $this->sendAttachmentWithRetryToken($userId, 'file', $uploadToken, $caption, $token);
    }

    public function sendAudioWithToken(string $userId, string $audioPath, string $token): array
    {
        $uploadToken = $this->uploadFileWithToken($audioPath, 'audio', $token);
        if (!$uploadToken) {
            return ['success' => false, 'error' => 'Failed to upload audio'];
        }
        sleep(2);
        return $this->sendAttachmentWithRetryToken($userId, 'audio', $uploadToken, null, $token);
    }

    public function sendVideoWithToken(string $userId, string $videoPath, ?string $caption, string $token): array
    {
        $uploadToken = $this->uploadFileWithToken($videoPath, 'video', $token);
        if (!$uploadToken) {
            return ['success' => false, 'error' => 'Failed to upload video'];
        }
        sleep(2);
        return $this->sendAttachmentWithRetryToken($userId, 'video', $uploadToken, $caption, $token);
    }

    // ─── Загрузка файлов ─────────────────────────────────────────

    public function uploadFile(string $filePath, string $type, string $domain, ?string $originalName = null): ?string
    {
        $token = $this->getTokenForDomain($domain);
        if (!$token) {
            $this->logger->error('Max API token not found', ['domain' => $domain]);
            return null;
        }
        return $this->doUpload($filePath, $type, $token, $originalName);
    }

    public function uploadFileWithToken(string $filePath, string $type, string $token, ?string $originalName = null): ?string
    {
        return $this->doUpload($filePath, $type, $token, $originalName);
    }

    private function doUpload(string $filePath, string $type, string $token, ?string $originalName = null): ?string
    {
        // 1. Получаем URL для загрузки
        $uploadUrlResponse = $this->makeRequestWithToken("uploads?type={$type}", [], $token, 'POST');

        if (!$uploadUrlResponse['success'] || empty($uploadUrlResponse['data']['url'])) {
            $this->logger->error('Failed to get upload URL', [
                'type'     => $type,
                'response' => $uploadUrlResponse,
            ]);
            return null;
        }

        $uploadUrl  = $uploadUrlResponse['data']['url'];
        $isTempFile = false;

        // 2. Если это URL — скачиваем во временный файл
        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            $filePath   = $this->downloadFileFromUrl($filePath);
            $isTempFile = true;
            if (!$filePath) {
                return null;
            }
        }

        if (!file_exists($filePath)) {
            $this->logger->error('File not found for upload', ['path' => $filePath]);
            if ($isTempFile) @unlink($filePath);
            return null;
        }

        // 3. Загружаем файл
        $uploadToken = $this->uploadToUrl($uploadUrl, $filePath, $type, $token, $originalName);

        if ($isTempFile && file_exists($filePath)) {
            unlink($filePath);
        }

        return $uploadToken;
    }

    private function downloadFileFromUrl(string $url): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'max_upload_');
        if (!$tempFile) {
            $this->logger->error('Failed to create temp file');
            return null;
        }

        $ch = curl_init($url);
        $fp = fopen($tempFile, 'wb');
        if (!$fp) {
            @unlink($tempFile);
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_MAXREDIRS      => 10,
        ]);

        $success   = curl_exec($ch);
        $error     = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fileSize  = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);

        if (!$success || $fileSize === 0) {
            $this->logger->error('Failed to download file from URL', [
                'url'       => $url,
                'error'     => $error,
                'http_code' => $httpCode,
            ]);
            unlink($tempFile);
            return null;
        }

        return $tempFile;
    }

    private function uploadToUrl(string $uploadUrl, string $filePath, string $type, string $token, ?string $originalName = null): ?string
    {
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $fileName = $originalName ?: basename($filePath);

        if (!$fileName || $fileName === $filePath) {
            $fileName = 'file_' . time() . '.' . $this->getExtensionFromMimeType($mimeType);
        }

        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['data' => curl_file_create($filePath, $mimeType, $fileName)],
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logger->error('File upload curl error', ['error' => $curlError]);
            return null;
        }

        $result      = json_decode($response, true);
        $uploadToken = $result['token'] ?? null;

        if (!$uploadToken) {
            $this->logger->error('No token in upload response', [
                'http_code' => $httpCode,
                'response'  => $result,
                'type'      => $type,
            ]);
        }

        return $uploadToken;
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $map = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'     => 'xlsx',
            'application/vnd.ms-excel'                                               => 'xls',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword'                                                     => 'doc',
            'application/pdf'                                                        => 'pdf',
            'image/jpeg'                                                             => 'jpg',
            'image/png'                                                              => 'png',
            'image/gif'                                                              => 'gif',
            'image/webp'                                                             => 'webp',
            'application/zip'                                                        => 'zip',
            'text/plain'                                                             => 'txt',
            'audio/mpeg'                                                             => 'mp3',
            'video/mp4'                                                              => 'mp4',
        ];
        return $map[$mimeType] ?? 'bin';
    }

    // ─── Retry для вложений ───────────────────────────────────────

    private function sendAttachmentWithRetry(string $userId, string $type, string $token, ?string $caption, string $domain, int $maxRetries = 3): array
    {
        $data = [
            'text'        => $caption ?? '',
            'attachments' => [['type' => $type, 'payload' => ['token' => $token]]],
        ];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $result = $this->makeRequest("messages?user_id={$userId}", $data, $domain);
            if ($result['success'] || ($result['response']['code'] ?? '') !== 'attachment.not.ready') {
                return $result;
            }
            sleep(pow(2, $attempt - 1));
        }

        return $result;
    }

    private function sendAttachmentWithRetryToken(string $userId, string $type, string $uploadToken, ?string $caption, string $apiToken, int $maxRetries = 3): array
    {
        $data = [
            'text'        => $caption ?? '',
            'attachments' => [['type' => $type, 'payload' => ['token' => $uploadToken]]],
        ];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $result = $this->makeRequestWithToken("messages?user_id=", $data, $apiToken);
            // userId передаётся отдельно — исправляем:
            $result = $this->makeRequestWithToken("messages?user_id={$userId}", $data, $apiToken);
            if ($result['success'] || ($result['response']['code'] ?? '') !== 'attachment.not.ready') {
                return $result;
            }
            sleep(pow(2, $attempt - 1));
        }

        return $result;
    }

    // ─── Прочие методы API ────────────────────────────────────────

    public function setWebhook(string $webhookUrl, string $domain): array
    {
        return $this->makeRequest('subscriptions', [
            'url'          => $webhookUrl,
            'update_types' => ['message_created', 'bot_started'],
            'secret'       => 'your_secret',
        ], $domain);
    }

    public function deleteWebhook(string $webhookUrl, string $domain): array
    {
        return $this->makeRequest('subscriptions', ['url' => $webhookUrl], $domain, 'DELETE');
    }

    public function getWebhookInfo(string $domain): array
    {
        return $this->makeRequest('me', [], $domain, 'GET');
    }

    public function getUserInfo(string $userId, string $domain): array
    {
        return $this->makeRequest("users/{$userId}", [], $domain, 'GET');
    }

    public function getFile(string $fileId, string $domain): ?array
    {
        $result = $this->makeRequest("files/{$fileId}", [], $domain, 'GET');
        return ($result['success'] ?? false) ? $result['data'] : null;
    }

    public function getFileUrl(string $filePath): string
    {
        return $this->apiUrl . '/files/' . $filePath;
    }

    public function checkConnection(string $domain): bool
    {
        return !empty($this->getTokenForDomain($domain));
    }

    // ─── HTTP транспорт ───────────────────────────────────────────

    private function makeRequest(string $endpoint, array $data, string $domain, string $method = 'POST'): array
    {
        $token = $this->getTokenForDomain($domain);

        if (!$token) {
            $this->logger->error('Max API token not found', ['domain' => $domain]);
            return ['success' => false, 'error' => 'Max API token not found for domain'];
        }

        return $this->makeRequestWithToken($endpoint, $data, $token, $method);
    }

    private function makeRequestWithToken(string $endpoint, array $data, string $token, string $method = 'POST'): array
    {
        $url = $this->apiUrl . '/' . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logger->error('Max API curl error', ['endpoint' => $endpoint, 'error' => $curlError]);
            return ['success' => false, 'error' => $curlError];
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200) {
            $this->logger->error('Max API error', [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
                'response'  => $result,
            ]);
            return [
                'success'   => false,
                'error'     => 'API returned error code: ' . $httpCode,
                'response'  => $result,
                'http_code' => $httpCode,
            ];
        }

        return ['success' => true, 'data' => $result, 'http_code' => $httpCode];
    }
}