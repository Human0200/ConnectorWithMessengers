<?php

declare(strict_types=1);

namespace BitrixTelegram\Services;

use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Repositories\TokenRepository;

/**
 * Сервис для работы с Max API
 * Аналогичен TelegramService, но для мессенджера Max
 */
class MaxService
{
    private string $apiUrl;
    private TokenRepository $tokenRepository;
    private Logger $logger;

    public function __construct(
        TokenRepository $tokenRepository,
        Logger $logger,
        string $apiUrl = 'https://platform-api.max.ru'
    ) {
        $this->apiUrl = $apiUrl;
        $this->tokenRepository = $tokenRepository;
        $this->logger = $logger;
    }

    /**
     * Получить токен Max для домена
     */
    private function getTokenForDomain(string $domain): ?string
    {
        $tokenData = $this->tokenRepository->findByDomain($domain);
        return $tokenData['api_token_max'] ?? null;
    }

    /**
     * Отправка текстового сообщения
     */
    public function sendMessage(string $userId, string $text, string $domain): array
    {
        return $this->makeRequest("messages?user_id={$userId}", [
            'text' => $text,
        ], $domain);
    }

    /**
     * Отправка сообщения с кнопками
     */
    public function sendMessageWithButtons(string $userId, string $text, array $buttons, string $domain): array
    {
        return $this->makeRequest("messages?user_id={$userId}", [
            'text' => $text,
            'attachments' => [
                [
                    'type' => 'inline_keyboard',
                    'payload' => [
                        'buttons' => $buttons
                    ]
                ]
            ]
        ], $domain);
    }

    /**
     * Загрузить файл (локальный или по URL)
     */
    public function uploadFile(string $filePath, string $type, string $domain, ?string $originalName = null): ?string
    {
        // Определяем, это URL или локальный путь
        $isUrl = filter_var($filePath, FILTER_VALIDATE_URL);

        // Сохраняем оригинальное имя файла если оно пришло из URL
        $urlOriginalName = $originalName;

        // Получаем URL для загрузки
        $uploadUrlResponse = $this->makeRequest("uploads?type={$type}", [], $domain, 'POST');

        if (!$uploadUrlResponse['success'] || empty($uploadUrlResponse['data']['url'])) {
            $this->logger->error('Failed to get upload URL', [
                'type' => $type,
                'response' => $uploadUrlResponse,
                'domain' => $domain,
            ]);
            return null;
        }

        $uploadUrl = $uploadUrlResponse['data']['url'];

        // Если это URL, сначала скачиваем файл
        if ($isUrl) {
            $tempFile = $this->downloadFileFromUrl($filePath);
            if (!$tempFile) {
                $this->logger->error('Failed to download file from URL', ['url' => $filePath]);
                return null;
            }

            $filePath = $tempFile;
            $isTempFile = true;
        } else {
            $isTempFile = false;
        }

        // Проверяем существование локального файла
        if (!file_exists($filePath)) {
            $this->logger->error('File not found', ['path' => $filePath]);
            if ($isTempFile) {
                @unlink($filePath);
            }
            return null;
        }

        // Загружаем файл на полученный URL, передаем оригинальное имя
        $token = $this->uploadToUrl($uploadUrl, $filePath, $type, $domain, $urlOriginalName);

        // Удаляем временный файл если был создан
        if ($isTempFile && file_exists($filePath)) {
            unlink($filePath);
        }

        return $token;
    }

    /**
     * Скачать файл по URL во временный файл
     */
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
            $this->logger->error('Failed to open temp file for writing', ['path' => $tempFile]);
            @unlink($tempFile);
            return null;
        }

        // Для Bitrix24 файлов может потребоваться авторизация
        $headers = [];

        // Добавляем заголовки авторизации если URL содержит bitrix24
        if (strpos($url, 'bitrix24') !== false) {
            // Можно добавить логику для получения токена Bitrix24 если нужно
            $headers[] = 'User-Agent: Mozilla/5.0 (compatible; MaxUploader/1.0)';
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300, // 5 минут для больших файлов
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_MAXREDIRS => 10,
        ]);

        $success = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fileSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);

        curl_close($ch);
        fclose($fp);

        if (!$success) {
            $this->logger->error('Failed to download file from URL', [
                'url' => $url,
                'error' => $error,
                'http_code' => $httpCode,
            ]);

            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            return null;
        }

        // Проверяем размер скачанного файла
        if ($fileSize === 0) {
            $this->logger->error('Downloaded file is empty', ['url' => $url]);
            unlink($tempFile);
            return null;
        }

        $this->logger->debug('File downloaded successfully', [
            'url' => $url,
            'size' => $fileSize,
            'temp_path' => $tempFile,
            'http_code' => $httpCode,
        ]);

        return $tempFile;
    }

    /**
     * Загрузить файл по URL в Max
     */
    private function uploadToUrl(string $uploadUrl, string $filePath, string $type, string $domain, ?string $originalName = null): ?string
    {
        $token = $this->getTokenForDomain($domain);

        if (!file_exists($filePath)) {
            $this->logger->error('File not found for upload', ['path' => $filePath]);
            return null;
        }


        $mimeType = mime_content_type($filePath);


        $fileName = $originalName ?: basename($filePath);


        if (!$fileName || $fileName === $filePath) {
            $extension = $this->getExtensionFromMimeType($mimeType);
            $fileName = 'file_' . time() . '.' . $extension;
        }

        $ch = curl_init($uploadUrl);

        $file = curl_file_create($filePath, $mimeType, $fileName);

        $postData = ['data' => $file];

        $headers = [
            'Authorization: ' . $token,
            'Accept: application/json'
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error('File upload curl error', [
                'url' => $uploadUrl,
                'error' => $error,
                'type' => $type,
                'domain' => $domain,
            ]);
            return null;
        }

        $result = json_decode($response, true);

        $this->logger->debug('Upload response', [
            'http_code' => $httpCode,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'type' => $type,
        ]);

        $token = $result['token'] ?? null;

        if (!$token) {
            $this->logger->error('No token in upload response', [
                'response' => $result,
                'http_code' => $httpCode,
                'type' => $type,
                'domain' => $domain,
            ]);
        }

        return $token;
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $extensions = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'video/mp4' => 'mp4',
            'video/avi' => 'avi',
        ];

        return $extensions[$mimeType] ?? 'bin';
    }

    /**
     * Отправка изображения
     */
    public function sendImage(string $userId, string $imageUrl, ?string $caption = null, string $domain): array
    {
        $data = [
            'text' => $caption ?? '',
            'attachments' => [
                [
                    'type' => 'image',
                    'payload' => [
                        'url' => $imageUrl
                    ]
                ]
            ]
        ];

        return $this->makeRequest("messages?user_id={$userId}", $data, $domain);
    }

    /**
     * Отправка файла с загрузкой
     */
    public function sendFile(string $userId, string $filePath, ?string $caption = null, string $domain, ?string $originalName = null): array
    {
        $token = $this->uploadFile($filePath, 'file', $domain, $originalName);

        if (!$token) {
            return [
                'success' => false,
                'error' => 'Failed to upload file',
            ];
        }

        sleep(2);
        return $this->sendAttachmentWithRetry($userId, 'file', $token, $caption, $domain);
    }

    /**
     * Отправка вложения с повторными попытками при ошибке attachment.not.ready
     */
    private function sendAttachmentWithRetry(string $userId, string $type, string $token, ?string $caption, string $domain, int $maxRetries = 3): array
    {
        $data = [
            'text' => $caption ?? '',
            'attachments' => [
                [
                    'type' => $type,
                    'payload' => [
                        'token' => $token
                    ]
                ]
            ]
        ];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $result = $this->makeRequest("messages?user_id={$userId}", $data, $domain);

            // Проверяем, это ли ошибка "файл не готов"
            $isNotReadyError = false;
            if (isset($result['response']['code']) && $result['response']['code'] === 'attachment.not.ready') {
                $isNotReadyError = true;
            }


            if ($result['success'] || !$isNotReadyError) {
                return $result;
            }

            $delay = pow(2, $attempt - 1);
            sleep($delay);
        }

        return $result;
    }

    /**
     * Отправка аудио с загрузкой файла
     */
    public function sendAudio(string $userId, string $audioPath, string $domain): array
    {
        $token = $this->uploadFile($audioPath, 'audio', $domain);

        if (!$token) {
            return [
                'success' => false,
                'error' => 'Failed to upload audio',
            ];
        }

        sleep(2);
        return $this->sendAttachmentWithRetry($userId, 'audio', $token, null, $domain);
    }

    /**
     * Отправка видео с загрузкой файла
     */
    public function sendVideo(string $userId, string $videoPath, ?string $caption = null, string $domain): array
    {
        $token = $this->uploadFile($videoPath, 'video', $domain);

        if (!$token) {
            return [
                'success' => false,
                'error' => 'Failed to upload video',
            ];
        }

        sleep(2);
        return $this->sendAttachmentWithRetry($userId, 'video', $token, $caption, $domain);
    }

    /**
     * Отправка сообщения с уже готовым токеном (если файл уже загружен)
     */
    public function sendMessageWithAttachment(string $userId, string $type, string $token, ?string $caption = null, string $domain): array
    {
        $data = [
            'text' => $caption ?? '',
            'attachments' => [
                [
                    'type' => $type,
                    'payload' => [
                        'token' => $token
                    ]
                ]
            ]
        ];

        return $this->makeRequest("messages?user_id={$userId}", $data, $domain);
    }


    /**
     * Получение информации о файле
     */
    public function getFile(string $fileId, string $domain): ?array
    {
        $result = $this->makeRequest("files/{$fileId}", [], $domain, 'GET');
        return $result['success'] ?? false ? $result['data'] : null;
    }

    /**
     * Получение URL файла
     */
    public function getFileUrl(string $filePath): string
    {
        return $this->apiUrl . '/files/' . $filePath;
    }

    /**
     * Установка вебхука
     */
    public function setWebhook(string $webhookUrl, string $domain): array
    {
        return $this->makeRequest('subscriptions', [
            'url' => $webhookUrl,
            'update_types' => ["message_created", "bot_started"],
            "secret" => "your_secret"
        ], $domain);
    }

    /**
     * Удаление вебхука
     */
    public function deleteWebhook(string $webhookUrl, string $domain): array
    {
        return $this->makeRequest('subscriptions', ['url' => $webhookUrl], $domain, 'DELETE');
    }

    /**
     * Получение информации о вебхуке
     */
    public function getWebhookInfo(string $domain): array
    {
        return $this->makeRequest('me', [], $domain, 'GET');
    }

    /**
     * Получение информации о пользователе
     */
    public function getUserInfo(string $userId, string $domain): array
    {
        return $this->makeRequest("users/{$userId}", [], $domain, 'GET');
    }

    /**
     * Отправка действия (печатает)
     */
    public function sendChatAction(string $userId, string $action, string $domain): array
    {
        $allowedActions = ['typing', 'upload_photo', 'upload_document', 'upload_video', 'upload_audio'];

        if (!in_array($action, $allowedActions)) {
            return [
                'success' => false,
                'error' => 'Invalid action',
            ];
        }

        return $this->makeRequest("chats/{$userId}/actions", [
            'action' => $action
        ], $domain);
    }


    /**
     * Выполнение запроса к Max API
     */
    private function makeRequest(string $endpoint, array $data = [], string $domain, string $method = 'POST'): array
    {
        $baseUrl = rtrim($this->apiUrl, '/');
        $cleanEndpoint = ltrim($endpoint, '/');
        $url = $baseUrl . '/' . $cleanEndpoint;

        // Получаем токен для домена
        $token = $this->getTokenForDomain($domain);
        $token = $this->cleanToken($token);

        if (!$token) {
            $this->logger->error('Max API token not found', ['domain' => $domain]);
            return [
                'success' => false,
                'error' => 'Max API token not found for domain',
            ];
        }

        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . trim($token),
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        } elseif ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error('Max API curl error', [
                'endpoint' => $endpoint,
                'error' => $error,
                'domain' => $domain,
            ]);

            return [
                'success' => false,
                'error' => $error,
            ];
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200) {
            $this->logger->error('Max API error', [
                'apiUrl' => $this->apiUrl,
                'method' => $method,
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'response' => $response,
                'domain' => $domain,
                'token' => $token,
            ]);

            return [
                'success' => false,
                'error' => 'API returned error code: ' . $httpCode,
                'response' => $result,
                'http_code' => $httpCode
            ];
        }

        $this->logger->debug('Max API success', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'domain' => $domain,
        ]);

        return [
            'success' => true,
            'data' => $result,
            'http_code' => $httpCode
        ];
    }

    private function cleanToken(string $token): string
{
    $token = preg_replace('/[^\x20-\x7E]/', '', $token);
    
    $token = trim($token);
    
    $token = str_replace(["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE"], '', $token);
    
    return $token;
}

    /**
     * Проверить доступность API для домена
     */
    public function checkConnection(string $domain): bool
    {
        $token = $this->getTokenForDomain($domain);
        return !empty($token);
    }
}
