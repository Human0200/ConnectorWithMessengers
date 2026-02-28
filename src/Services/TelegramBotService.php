<?php

declare(strict_types=1);

namespace BitrixTelegram\Services;

use BitrixTelegram\Helpers\Logger;

/**
 * Инкапсулирует все прямые вызовы Telegram Bot API.
 *
 * Файлы из Bitrix24 передаются по защищённым URL (требуют авторизации),
 * поэтому Telegram не может скачать их напрямую.
 * Решение: скачиваем файл на сервер → загружаем в Telegram через multipart.
 */
class TelegramBotService
{
    public function __construct(
        private Logger $logger
    ) {}

    // ─── Отправка сообщений ───────────────────────────────────────

    public function sendMessage(string $token, string $chatId, string $text): array
    {
        return $this->call($token, 'sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Отправить фото. Сначала скачиваем файл на сервер, затем загружаем через multipart.
     * Telegram не умеет скачивать файлы по защищённым URL (Bitrix24 требует авторизацию).
     */
    public function sendPhoto(string $token, string $chatId, string $photoUrl, string $caption = ''): array
    {
        $tempFile = $this->downloadToTemp($photoUrl);

        if ($tempFile) {
            $result = $this->callMultipart($token, 'sendPhoto', [
                'chat_id'    => $chatId,
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ], 'photo', $tempFile);
            unlink($tempFile);
            return $result;
        }

        // Fallback: по URL (только для публичных ссылок)
        $this->logger->warning('TelegramBotService: sendPhoto fallback to URL');
        return $this->call($token, 'sendPhoto', [
            'chat_id'    => $chatId,
            'photo'      => $photoUrl,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Отправить документ. Сначала скачиваем файл на сервер, затем загружаем через multipart.
     */
    public function sendDocument(string $token, string $chatId, string $fileUrl, string $caption = '', ?string $fileName = null): array
    {
        $tempFile = $this->downloadToTemp($fileUrl);

        if ($tempFile) {
            $result = $this->callMultipart($token, 'sendDocument', [
                'chat_id'    => $chatId,
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ], 'document', $tempFile, $fileName);
            unlink($tempFile);
            return $result;
        }

        // Fallback: по URL
        $this->logger->warning('TelegramBotService: sendDocument fallback to URL');
        return $this->call($token, 'sendDocument', [
            'chat_id'    => $chatId,
            'document'   => $fileUrl,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    // ─── Файлы ────────────────────────────────────────────────────

    public function extractFiles(array $message, string $token): array
    {
        $fileId = null;
        $type   = 'file';
        $name   = 'file';

        if (!empty($message['photo'])) {
            $photo  = end($message['photo']);
            $fileId = $photo['file_id'];
            $type   = 'image';
            $name   = 'photo.jpg';
        } elseif (!empty($message['document'])) {
            $fileId = $message['document']['file_id'];
            $type   = 'file';
            $name   = $message['document']['file_name'] ?? 'document';
        } elseif (!empty($message['voice'])) {
            $fileId = $message['voice']['file_id'];
            $type   = 'audio';
            $name   = 'voice.ogg';
        } elseif (!empty($message['video'])) {
            $fileId = $message['video']['file_id'];
            $type   = 'video';
            $name   = $message['video']['file_name'] ?? 'video.mp4';
        }

        if (!$fileId) {
            return [];
        }

        $fileInfo = $this->call($token, 'getFile', ['file_id' => $fileId]);

        if (empty($fileInfo['ok']) || empty($fileInfo['result']['file_path'])) {
            $this->logger->warning('TelegramBotService: failed to get file info', ['file_id' => $fileId]);
            return [];
        }

        $url = 'https://api.telegram.org/file/bot' . $token . '/' . $fileInfo['result']['file_path'];

        return [['url' => $url, 'name' => $name, 'type' => $type]];
    }

    // ─── Скачивание файла ─────────────────────────────────────────

    private function downloadToTemp(string $url): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'tgbot_upload_');
        if (!$tempFile) {
            $this->logger->error('TelegramBotService: failed to create temp file');
            return null;
        }

        $fp = fopen($tempFile, 'wb');
        if (!$fp) {
            @unlink($tempFile);
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; BitrixConnector/1.0)',
        ]);

        $success  = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fileSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);

        if (!$success || $fileSize === 0 || $httpCode >= 400) {
            $this->logger->error('TelegramBotService: file download failed', [
                'url'        => mb_substr($url, 0, 100),
                'http_code'  => $httpCode,
                'curl_error' => $error,
                'file_size'  => $fileSize,
            ]);
            @unlink($tempFile);
            return null;
        }

        $this->logger->info('TelegramBotService: file downloaded to temp', [
            'size'      => $fileSize,
            'http_code' => $httpCode,
        ]);

        return $tempFile;
    }

    // ─── Транспорт ────────────────────────────────────────────────

    private function callMultipart(string $token, string $method, array $params, string $fieldName, string $filePath, ?string $fileName = null): array
    {
        $url      = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        $this->logger->info('TgBot callMultipart', ['method' => $method, 'fileName_received' => $fileName, 'mimeType' => $mimeType]);

        if (!$fileName) {
            $ext      = $this->getExtensionFromMimeType($mimeType);
            $fileName = $fieldName . '_' . time() . '.' . $ext;
        }

        $params[$fieldName] = curl_file_create($filePath, $mimeType, $fileName);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            $this->logger->error('TelegramBotService: multipart cURL error', ['method' => $method, 'error' => $curlError]);
            return ['ok' => false, 'error' => $curlError];
        }

        $result = json_decode($response, true) ?? ['ok' => false];

        if (empty($result['ok'])) {
            $this->logger->error('TelegramBotService: API error', ['method' => $method, 'http_code' => $httpCode, 'response' => $result]);
        }

        return $result;
    }

    private function call(string $token, string $method, array $params): array
    {
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logger->error('TelegramBotService: cURL error', ['method' => $method, 'error' => $curlError]);
            return ['ok' => false, 'error' => $curlError];
        }

        return json_decode($response, true) ?? ['ok' => false];
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        return [
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            'image/gif'        => 'gif',
            'image/webp'       => 'webp',
            'application/pdf'  => 'pdf',
            'application/zip'  => 'zip',
            'text/plain'       => 'txt',
            'audio/mpeg'       => 'mp3',
            'audio/ogg'        => 'ogg',
            'video/mp4'        => 'mp4',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ][$mimeType] ?? 'bin';
    }
}