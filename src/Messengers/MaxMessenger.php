<?php

declare(strict_types=1);

namespace BitrixTelegram\Messengers;

use BitrixTelegram\Services\MaxService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

class MaxMessenger implements MessengerInterface
{
    private TokenRepository $tokenRepository;
    private Logger $logger;
    private ?string $currentDomain = null;
    private MaxService $maxService;

    public function __construct(
        TokenRepository $tokenRepository,
        Logger $logger,
        MaxService $maxService
    ) {
        $this->tokenRepository = $tokenRepository;
        $this->logger = $logger;
        $this->maxService = $maxService;
    }

    /**
     * Установить домен для текущей операции
     */
    public function setDomain(string $domain): void
    {
        $this->currentDomain = $domain;
        $this->logger->debug('Domain set for MaxMessenger', ['domain' => $domain]);
    }

    /**
     * Получить текущий домен
     */
    public function getDomain(): ?string
    {
        return $this->currentDomain;
    }

    /**
     * Проверить установлен ли домен
     */
    private function checkDomain(): bool
    {
        if (!$this->currentDomain) {
            $this->logger->error('Domain not set for MaxMessenger');
            return false;
        }
        return true;
    }

    public function sendMessage(string $chatId, string $text): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        $result = $this->maxService->sendMessage($chatId, $text, $this->currentDomain);

        // Приводим к единому формату ответа
        return [
            'ok' => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        // Для URL используем sendImage
        if (filter_var($photoUrl, FILTER_VALIDATE_URL)) {
            $result = $this->maxService->sendImage($chatId, $photoUrl, $caption, $this->currentDomain);
        } else {
            // Для локальных файлов используем sendFile
            $result = $this->maxService->sendFile($chatId, $photoUrl, $caption, $this->currentDomain);
        }

        return [
            'ok' => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    public function sendDocument(string $chatId, string $documentUrl, ?string $caption = null, ?array $fileData = null): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }
        $originalName = $fileData['name'] ?? $fileData['filename'] ?? null;

        $result = $this->maxService->sendFile(
            $chatId,
            $documentUrl,
            $caption,
            $this->currentDomain,
            $originalName
        );

        return [
            'ok' => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    public function sendVoice(string $chatId, string $voiceUrl): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        $result = $this->maxService->sendAudio($chatId, $voiceUrl, $this->currentDomain);

        return [
            'ok' => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    public function sendVideo(string $chatId, string $videoUrl, ?string $caption = null): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        $result = $this->maxService->sendVideo($chatId, $videoUrl, $caption, $this->currentDomain);

        return [
            'ok' => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    public function getFile(string $fileId): ?array
    {
        if (!$this->checkDomain()) {
            return null;
        }

        $result = $this->maxService->getFile($fileId, $this->currentDomain);
        return $result;
    }

    public function getFileUrl(string $filePath): string
    {
        return $this->maxService->getFileUrl($filePath);
    }

    public function setWebhook(string $webhookUrl): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        $result = $this->maxService->setWebhook($webhookUrl, $this->currentDomain);

        return [
            'ok' => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    public function getInfo(): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        // Используем getWebhookInfo или getUserInfo в зависимости от потребностей
        $result = $this->maxService->getWebhookInfo($this->currentDomain);

        if (!$result['success']) {
            // Если не удалось получить информацию о вебхуке, пробуем получить информацию о пользователе
            $result = $this->maxService->getUserInfo('me', $this->currentDomain);
        }

        return [
            'ok' => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    public function normalizeIncomingMessage(array $message): array
    {
        // Проверяем структуру сообщения
        if (isset($message['message'])) {
            $rawMessage = $message['message'];
        } else {
            $rawMessage = $message;
        }

        $normalized = [
            'message_id' => null,
            'chat_id' => null,
            'user_id' => null,
            'user_name' => '',
            'text' => null,
            'timestamp' => null,
            'files' => [],
            'message_type' => 'text',
            'reply_to' => null,
            'raw' => $rawMessage
        ];

        try {
            // ИЗВЛЕКАЕМ chat_id - В Max это recipient.chat_id
            if (isset($rawMessage['recipient']['chat_id'])) {
                $normalized['chat_id'] = (string) $rawMessage['recipient']['chat_id'];
            }

            // Извлекаем user_id - ID отправителя
            if (isset($rawMessage['sender']['user_id'])) {
                $normalized['user_id'] = (string) $rawMessage['sender']['user_id'];
            }

            // Формируем имя пользователя
            $userNameParts = [];
            if (isset($rawMessage['sender']['first_name'])) {
                $userNameParts[] = $rawMessage['sender']['first_name'];
            }
            if (isset($rawMessage['sender']['last_name'])) {
                $userNameParts[] = $rawMessage['sender']['last_name'];
            }

            if (!empty($userNameParts)) {
                $normalized['user_name'] = implode(' ', array_filter($userNameParts));
            } elseif (isset($rawMessage['sender']['name'])) {
                $normalized['user_name'] = $rawMessage['sender']['name'];
            } else {
                $normalized['user_name'] = 'User';
            }

            // Извлекаем текст сообщения
            if (isset($rawMessage['body']['text'])) {
                $normalized['text'] = $rawMessage['body']['text'];
            }

            // Извлекаем message_id
            if (isset($rawMessage['body']['mid'])) {
                $normalized['message_id'] = $rawMessage['body']['mid'];
            }

            // Извлекаем timestamp
            if (isset($rawMessage['timestamp'])) {
                $normalized['timestamp'] = (int) $rawMessage['timestamp'];
            } elseif (isset($message['timestamp'])) {
                $normalized['timestamp'] = (int) $message['timestamp'];
            }

            // Проверяем наличие файлов в attachments
            if (
                isset($rawMessage['body']['attachments']) &&
                is_array($rawMessage['body']['attachments'])
            ) {
                foreach ($rawMessage['body']['attachments'] as $attachment) {
                    $fileInfo = $this->normalizeAttachment($attachment);
                    if ($fileInfo) {
                        $normalized['files'][] = $fileInfo;
                    }
                }
            }

            // Определяем тип сообщения
            $normalized['message_type'] = $this->detectMessageType($normalized);
        } catch (\Exception $e) {
            $this->logger->error('Error normalizing Max message: ' . $e->getMessage());
        }

        return $normalized;
    }

    private function detectMessageType(array $normalized): string
    {
        if (!empty($normalized['files'])) {
            $firstFile = $normalized['files'][0];
            return $firstFile['type'] ?? 'file';
        }

        return !empty($normalized['text']) ? 'text' : 'unknown';
    }

    /**
     * Нормализация вложения Max messenger
     */
    private function normalizeAttachment(array $attachment): ?array
    {
        if (!isset($attachment['type'])) {
            return null;
        }

        $fileInfo = [
            'id' => null,
            'type' => $attachment['type'],
            'url' => null,
            'mime_type' => null,
            'size' => null,
            'name' => null,
        ];

        try {
            switch ($attachment['type']) {
                case 'image':
                    if (isset($attachment['payload']['url'])) {
                        $fileInfo['url'] = $attachment['payload']['url'];
                        if (isset($attachment['payload']['photo_id'])) {
                            $fileInfo['id'] = (string) $attachment['payload']['photo_id'];
                        }
                        $extension = $this->getImageExtensionFromUrl($fileInfo['url']);
                        $fileInfo['name'] = 'image_' . time() . '.' . $extension;
                        $fileInfo['file_name'] = $fileInfo['name'];
                    }
                    break;

                case 'video':
                    if (isset($attachment['payload']['url'])) {
                        $fileInfo['url'] = $attachment['payload']['url'];
                        $fileInfo['name'] = 'video_' . time() . '.mp4';
                        $fileInfo['file_name'] = $fileInfo['name'];
                    }
                    break;

                case 'audio':
                    if (isset($attachment['payload']['url'])) {
                        $fileInfo['url'] = $attachment['payload']['url'];
                        $fileInfo['name'] = 'audio_' . time() . '.mp3';
                        $fileInfo['file_name'] = $fileInfo['name'];
                    }
                    break;

                case 'file':
                    if (isset($attachment['payload']['url'])) {
                        $fileInfo['url'] = $attachment['payload']['url'];
                        if (isset($attachment['payload']['filename'])) {
                            $fileInfo['name'] = $attachment['payload']['filename'];
                            $fileInfo['file_name'] = $fileInfo['name'];
                        } else {
                            $fileInfo['name'] = 'file_' . time();
                            $fileInfo['file_name'] = $fileInfo['name'];
                        }
                    }
                    break;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error normalizing Max attachment: ' . $e->getMessage());
            return null;
        }

        return !empty($fileInfo['url']) ? $fileInfo : null;
    }

    /**
     * Получить расширение изображения из URL
     */
    private function getImageExtensionFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (!empty($extension) && in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return strtolower($extension);
        }

        parse_str($parsed['query'] ?? '', $query);

        if (isset($query['ext'])) {
            return strtolower($query['ext']);
        }

        return 'jpg';
    }

    public function denormalizeOutgoingMessage(array $message): array
    {
        // Конвертируем универсальный формат в формат Max
        $result = [
            'user_id' => $message['chat_id'] ?? '',
        ];

        if (!empty($message['text'])) {
            $result['text'] = $message['text'];
        }

        if (!empty($message['files'])) {
            $file = $message['files'][0];
            $result['attachments'] = [
                [
                    'type' => $this->mapToMaxFileType($file['type'] ?? 'document'),
                    'payload' => [
                        'url' => $file['url'] ?? ''
                    ]
                ]
            ];
        }

        return $result;
    }

    private function mapToMaxFileType(string $type): string
    {
        $mapping = [
            'photo' => 'image',
            'image' => 'image',
            'document' => 'file',
            'voice' => 'audio',
            'audio' => 'audio',
            'video' => 'video',
        ];

        return $mapping[strtolower($type)] ?? 'file';
    }

    public function getType(): string
    {
        return 'max';
    }

    /**
     * Проверить подключение к API
     */
    public function checkConnection(): bool
    {
        if (!$this->currentDomain) {
            return false;
        }

        return $this->maxService->checkConnection($this->currentDomain);
    }
}
