<?php

declare(strict_types=1);

namespace BitrixTelegram\Messengers;

use Exception;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Services\MadelineProtoService;
use RuntimeException;

class MadelineProtoMessenger implements MessengerInterface
{
    private ?string $domain = null;
    private ?string $sessionId = null;
    private ?array $sessionInfo = null;

    public function __construct(
        private Logger $logger,
        private TokenRepository $tokenRepository,
        private MadelineProtoService $madelineProtoService
    ) {
    }

    /**
     * Установить домен для текущей операции
     */
    public function setDomain(string $domain): void
    {
        $this->domain = $domain;
        $this->logger->debug('MadelineProtoMessenger: domain set', ['domain' => $domain]);
    }

    /**
     * Установить идентификатор сессии
     */
    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
        if ($this->domain) {
            $this->sessionInfo = $this->madelineProtoService->getSessionInfo($this->domain, $sessionId);
        }
        $this->logger->debug('MadelineProtoMessenger: session ID set', ['session_id' => $sessionId]);
    }

    /**
     * Получить текущий домен
     */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * Получить текущий идентификатор сессии
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Проверить готовность к работе
     */
    private function validate(): bool
    {
        if (!$this->domain) {
            $this->logger->error('MadelineProtoMessenger: domain not set');
            return false;
        }
        
        if (!$this->sessionId) {
            $this->logger->error('MadelineProtoMessenger: session ID not set');
            return false;
        }
        
        // Проверяем существование сессии
        if (!$this->sessionInfo) {
            $this->sessionInfo = $this->madelineProtoService->getSessionInfo($this->domain, $this->sessionId);
        }
        
        if (!$this->sessionInfo || $this->sessionInfo['status'] !== 'authorized') {
            $this->logger->error('MadelineProtoMessenger: session not authorized', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'session_info' => $this->sessionInfo
            ]);
            return false;
        }
        
        return true;
    }

    /**
     * Отправить текстовое сообщение
     */
    public function sendMessage(string $chatId, string $text): array
    {
        try {
            if (!$this->validate()) {
                return [
                    'success' => false,
                    'error' => 'Domain or session not set or not authorized'
                ];
            }

            $this->logger->debug('MadelineProtoMessenger: sending message', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'text_length' => strlen($text),
            ]);

            $result = $this->madelineProtoService->sendMessage($chatId, $text, $this->domain, $this->sessionId);
            
            $this->logger->debug('MadelineProtoMessenger: message sent', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'message_id' => $result['message_id'] ?? null,
                'success' => $result['success'] ?? false,
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to send message', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Отправить фото
     */
    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null): array
    {
        try {
            if (!$this->validate()) {
                return [
                    'success' => false,
                    'error' => 'Domain or session not set or not authorized'
                ];
            }

            $this->logger->debug('MadelineProtoMessenger: sending photo', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'photo_url' => $photoUrl,
                'has_caption' => !empty($caption),
            ]);

            // Определяем, локальный файл или URL
            if (filter_var($photoUrl, FILTER_VALIDATE_URL)) {
                // Скачиваем файл временно
                $tempFile = $this->downloadFile($photoUrl, 'photo');
                if (!$tempFile) {
                    return [
                        'success' => false,
                        'error' => 'Failed to download photo'
                    ];
                }
                $filePath = $tempFile;
            } else {
                $filePath = $photoUrl;
            }

            $mp = $this->madelineProtoService->getInstance($this->domain, $this->sessionId);
            if (!$mp) {
                throw new RuntimeException('Failed to get MadelineProto instance');
            }

            // Отправляем фото через MadelineProto
            $cleanChatId = $this->extractTelegramId($chatId);
            $result = $mp->messages->sendMedia([
                'peer' => $cleanChatId,
                'media' => [
                    '_' => 'inputMediaUploadedPhoto',
                    'file' => $filePath
                ],
                'message' => $caption ?? '',
                'parse_mode' => 'HTML'
            ]);

            // Очищаем временный файл
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }

            return [
                'success' => true,
                'data' => $result,
                'message_id' => $result['id'] ?? null,
            ];
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to send photo', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            // Очищаем временный файл если есть
            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Отправить документ
     */
    public function sendDocument(string $chatId, string $documentUrl, ?string $caption = null, ?array $fileData = null): array
    {
        try {
            if (!$this->validate()) {
                return [
                    'success' => false,
                    'error' => 'Domain or session not set or not authorized'
                ];
            }

            $this->logger->debug('MadelineProtoMessenger: sending document', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'document_url' => $documentUrl,
                'has_file_data' => !empty($fileData),
            ]);

            // Если есть fileData (например, загруженный файл), используем его
            if ($fileData && isset($fileData['tmp_name'])) {
                $filePath = $fileData['tmp_name'];
                $fileName = $fileData['name'] ?? 'document';
            } elseif (filter_var($documentUrl, FILTER_VALIDATE_URL)) {
                // Скачиваем из URL
                $tempFile = $this->downloadFile($documentUrl, 'document');
                if (!$tempFile) {
                    return [
                        'success' => false,
                        'error' => 'Failed to download document'
                    ];
                }
                $filePath = $tempFile;
                $fileName = basename(parse_url($documentUrl, PHP_URL_PATH)) ?: 'document';
            } else {
                $filePath = $documentUrl;
                $fileName = basename($documentUrl) ?: 'document';
            }

            $mp = $this->madelineProtoService->getInstance($this->domain, $this->sessionId);
            if (!$mp) {
                throw new RuntimeException('Failed to get MadelineProto instance');
            }

            $cleanChatId = $this->extractTelegramId($chatId);
            $result = $mp->messages->sendMedia([
                'peer' => $cleanChatId,
                'media' => [
                    '_' => 'inputMediaUploadedDocument',
                    'file' => $filePath,
                    'attributes' => [
                        ['_' => 'documentAttributeFilename', 'file_name' => $fileName]
                    ]
                ],
                'message' => $caption ?? '',
                'parse_mode' => 'HTML'
            ]);

            // Очищаем временный файл
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }

            return [
                'success' => true,
                'data' => $result,
                'message_id' => $result['id'] ?? null,
            ];
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to send document', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Отправить голосовое сообщение
     */
    public function sendVoice(string $chatId, string $voiceUrl): array
    {
        try {
            if (!$this->validate()) {
                return [
                    'success' => false,
                    'error' => 'Domain or session not set or not authorized'
                ];
            }

            $this->logger->debug('MadelineProtoMessenger: sending voice', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'voice_url' => $voiceUrl,
            ]);

            if (filter_var($voiceUrl, FILTER_VALIDATE_URL)) {
                $tempFile = $this->downloadFile($voiceUrl, 'voice');
                if (!$tempFile) {
                    return [
                        'success' => false,
                        'error' => 'Failed to download voice'
                    ];
                }
                $filePath = $tempFile;
            } else {
                $filePath = $voiceUrl;
            }

            // Проверяем формат файла
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            if (!in_array(strtolower($extension), ['ogg', 'oga', 'opus', 'mp3', 'm4a', 'wav'])) {
                $this->logger->warning('MadelineProtoMessenger: voice file might need conversion', [
                    'extension' => $extension,
                ]);
            }

            $mp = $this->madelineProtoService->getInstance($this->domain, $this->sessionId);
            if (!$mp) {
                throw new RuntimeException('Failed to get MadelineProto instance');
            }

            $cleanChatId = $this->extractTelegramId($chatId);
            $result = $mp->messages->sendMedia([
                'peer' => $cleanChatId,
                'media' => [
                    '_' => 'inputMediaUploadedDocument',
                    'file' => $filePath,
                    'attributes' => [
                        ['_' => 'documentAttributeAudio', 'voice' => true]
                    ]
                ],
                'message' => '',
                'parse_mode' => 'HTML'
            ]);

            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }

            return [
                'success' => true,
                'data' => $result,
                'message_id' => $result['id'] ?? null,
            ];
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to send voice', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Отправить видео
     */
    public function sendVideo(string $chatId, string $videoUrl, ?string $caption = null): array
    {
        try {
            if (!$this->validate()) {
                return [
                    'success' => false,
                    'error' => 'Domain or session not set or not authorized'
                ];
            }

            $this->logger->debug('MadelineProtoMessenger: sending video', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'video_url' => $videoUrl,
                'has_caption' => !empty($caption),
            ]);

            if (filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                $tempFile = $this->downloadFile($videoUrl, 'video');
                if (!$tempFile) {
                    return [
                        'success' => false,
                        'error' => 'Failed to download video'
                    ];
                }
                $filePath = $tempFile;
            } else {
                $filePath = $videoUrl;
            }

            $mp = $this->madelineProtoService->getInstance($this->domain, $this->sessionId);
            if (!$mp) {
                throw new RuntimeException('Failed to get MadelineProto instance');
            }

            $cleanChatId = $this->extractTelegramId($chatId);
            $result = $mp->messages->sendMedia([
                'peer' => $cleanChatId,
                'media' => [
                    '_' => 'inputMediaUploadedDocument',
                    'file' => $filePath,
                    'attributes' => [
                        ['_' => 'documentAttributeVideo']
                    ]
                ],
                'message' => $caption ?? '',
                'parse_mode' => 'HTML'
            ]);

            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }

            return [
                'success' => true,
                'data' => $result,
                'message_id' => $result['id'] ?? null,
            ];
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to send video', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить информацию о файле
     */
    public function getFile(string $fileId): ?array
    {
        try {
            if (!$this->validate()) {
                $this->logger->error('MadelineProtoMessenger: cannot get file - not validated');
                return null;
            }

            $this->logger->debug('MadelineProtoMessenger: getting file info', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'file_id' => $fileId,
            ]);

            $mp = $this->madelineProtoService->getInstance($this->domain, $this->sessionId);
            if (!$mp) {
                throw new RuntimeException('Failed to get MadelineProto instance');
            }

            // В MadelineProto получение информации о файле зависит от контекста
            // Для простоты возвращаем базовую информацию
            
            return [
                'file_id' => $fileId,
                'exists' => true,
                'note' => 'Detailed file info requires specific context in MadelineProto',
                'messenger' => 'madelineproto'
            ];
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to get file info', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Получить URL файла
     */
    public function getFileUrl(string $filePath): string
    {
        try {
            if (!$this->validate()) {
                $this->logger->error('MadelineProtoMessenger: cannot get file URL - not validated');
                return '';
            }

            $this->logger->debug('MadelineProtoMessenger: getting file URL', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'file_path' => $filePath,
            ]);

            // MadelineProto не предоставляет прямых ссылок для скачивания
            // Нужно использовать методы API для скачивания
            
            return ''; // Пустая строка, так как прямых URL нет
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to get file URL', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Установить вебхук (для MadelineProto не поддерживается)
     */
    public function setWebhook(string $webhookUrl): array
    {
        $this->logger->warning('MadelineProtoMessenger: webhooks not supported', [
            'domain' => $this->domain,
            'session_id' => $this->sessionId,
            'webhook_url' => $webhookUrl,
        ]);

        return [
            'success' => false,
            'error' => 'Webhooks are not supported for user accounts in MadelineProto',
            'message' => 'MadelineProto работает через long polling, вебхуки не поддерживаются',
        ];
    }

    /**
     * Получить информацию о текущем аккаунте
     */
    public function getInfo(): array
    {
        try {
            if (!$this->domain || !$this->sessionId) {
                return [
                    'success' => false,
                    'error' => 'Domain or session not set'
                ];
            }

            $sessionInfo = $this->madelineProtoService->getSessionInfo($this->domain, $this->sessionId);
            
            if (!$sessionInfo || $sessionInfo['status'] !== 'authorized') {
                return [
                    'success' => false,
                    'error' => 'Session not authorized',
                    'session_status' => $sessionInfo['status'] ?? 'unknown'
                ];
            }

            // Если в сессии уже есть информация об аккаунте
            if ($sessionInfo['account_id'] && $sessionInfo['account_first_name']) {
                return [
                    'success' => true,
                    'id' => $sessionInfo['account_id'],
                    'username' => $sessionInfo['account_username'] ?? null,
                    'first_name' => $sessionInfo['account_first_name'] ?? null,
                    'last_name' => $sessionInfo['account_last_name'] ?? null,
                    'phone' => $sessionInfo['account_phone'] ?? null,
                    'type' => 'user_account',
                    'session_name' => $sessionInfo['session_name'] ?? null,
                    'session_id' => $this->sessionId,
                    'domain' => $this->domain
                ];
            }

            // Получаем свежую информацию из MadelineProto
            $mp = $this->madelineProtoService->getInstance($this->domain, $this->sessionId);
            if (!$mp) {
                return [
                    'success' => false,
                    'error' => 'Failed to get MadelineProto instance'
                ];
            }

            $me = $mp->getSelf();
            if (!$me) {
                return [
                    'success' => false,
                    'error' => 'Failed to get account info'
                ];
            }

            return [
                'success' => true,
                'id' => $me['id'] ?? null,
                'username' => $me['username'] ?? null,
                'first_name' => $me['first_name'] ?? null,
                'last_name' => $me['last_name'] ?? null,
                'phone' => $me['phone'] ?? null,
                'type' => 'user_account',
                'session_name' => $sessionInfo['session_name'] ?? null,
                'session_id' => $this->sessionId,
                'domain' => $this->domain,
                'raw' => $me
            ];
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to get account info', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Преобразовать сообщение из MadelineProto в универсальный формат
     */
public function normalizeIncomingMessage($rawMessage): array
{
    // ── 1. Приводим к массиву ────────────────────────────────────
    if (!is_array($rawMessage)) {
        if (is_string($rawMessage)) {
            $decoded = json_decode($rawMessage, true);
            if (is_array($decoded)) {
                $rawMessage = $decoded;
            } else {
                return $this->emptyNormalized($rawMessage);
            }
        } else {
            return $this->emptyNormalized($rawMessage);
        }
    }

    // ── 2. Метаданные сессии ─────────────────────────────────────
    $sessionId   = $rawMessage['session_id']   ?? null;
    $sessionName = $rawMessage['session_name'] ?? null;
    $domain      = $rawMessage['domain']       ?? null;

    // ── 3. Данные самого сообщения ───────────────────────────────
    $msg = isset($rawMessage['message']) && is_array($rawMessage['message'])
        ? $rawMessage['message']
        : $rawMessage;

    $isOutgoing = !empty($msg['out']);

    // ── 4. Числовые id из MadelineProto ─────────────────────────
    //  from_id  — кто отправил (для входящих = собеседник, для исходящих = мы)
    //  peer_id  — диалог      (для входящих = мы,         для исходящих = собеседник)
    $rawFromId = $this->extractRawId($msg['from_id'] ?? null);
    $rawPeerId = $this->extractRawId($msg['peer_id'] ?? null);

    // ── 5. Ключевой фикс: собеседник всегда одинаковый ──────────
    //  Входящее (out=false): написала Софья → from_id=Софья, peer_id=Антон → собеседник = Софья (from_id)
    //  Исходящее (out=true): Антон ответил → from_id=Антон, peer_id=Софья → собеседник = Софья (peer_id)
    $interlocutorId = $isOutgoing ? $rawPeerId : $rawFromId;

    if (!$interlocutorId) {
        // Fallback: хоть что-нибудь
        $interlocutorId = $rawFromId ?? $rawPeerId;
    }

    $chatId = $interlocutorId ? 'user_' . $interlocutorId : null;
    $userId = $interlocutorId;

    // ── 6. Имя пользователя ──────────────────────────────────────
    //  sender_name из webhook — имя того, чьё сообщение (может быть владелец или собеседник)
    //  Для входящих берём sender_name, для исходящих он не важен (это мы сами)
    $senderName = $rawMessage['sender_name'] ?? null;
    $userName   = (!$isOutgoing && $senderName) ? $senderName : 'Unknown';

    // ── 7. Текст ─────────────────────────────────────────────────
    $text = '';
    if (isset($msg['message']) && is_string($msg['message'])) {
        $text = $msg['message'];
    }

    $result = [
        'message_id'   => $msg['id']   ?? null,
        'date'         => $msg['date'] ?? time(),
        'text'         => $text,
        'chat_id'      => $chatId,
        'user_id'      => $userId,
        'user_name'    => $userName,
        'is_outgoing'  => $isOutgoing,
        'entities'     => is_array($msg['entities'] ?? null) ? $msg['entities'] : [],
        'media_type'   => null,
        'file_id'      => null,
        'session_id'   => $sessionId,
        'session_name' => $sessionName,
        'domain'       => $domain,
        'messenger_type' => 'madelineproto',
        'raw_peer_id'  => $rawPeerId,
        'raw_from_id'  => $rawFromId,
        'raw_data'     => $rawMessage,
    ];

    $this->logger->info('Normalized message', [
        'chatId'      => $chatId,
        'userName'    => $userName,
        'userId'      => $userId,
        'text'        => $text,
        'is_outgoing' => $isOutgoing,
    ]);

    return $result;
}

/**
 * Извлечь числовой id из from_id / peer_id.
 * MadelineProto может отдавать число или int напрямую.
 */
private function extractRawId(mixed $raw): ?int
{
    if ($raw === null) return null;
    if (is_int($raw))  return $raw;
    if (is_string($raw) && ctype_digit($raw)) return (int)$raw;
    // Массив (старый формат MadelineProto)
    if (is_array($raw)) {
        return isset($raw['user_id'])    ? (int)$raw['user_id']
             : (isset($raw['chat_id'])   ? (int)$raw['chat_id']
             : (isset($raw['channel_id'])? (int)$raw['channel_id']
             : null));
    }
    return null;
}

private function emptyNormalized(mixed $raw): array
{
    return [
        'message_id' => null, 'date' => time(), 'text' => '',
        'chat_id' => null, 'user_id' => null, 'user_name' => 'Unknown',
        'is_outgoing' => false, 'entities' => [], 'media_type' => null,
        'file_id' => null, 'session_id' => null, 'session_name' => null,
        'domain' => null, 'messenger_type' => 'madelineproto',
        'raw_peer_id' => null, 'raw_from_id' => null, 'raw_data' => $raw,
    ];
}

private function extractFileId($media): ?string
{
    // Проверяем, что $media - объект
    if (!is_object($media)) {
        return null;
    }
    
    // Извлечение file_id из медиа объекта
    if (isset($media->photo)) {
        return $media->photo->id ?? null;
    } elseif (isset($media->document)) {
        return $media->document->id ?? null;
    }
    return null;
}

    /**
     * Преобразовать универсальное сообщение в формат MadelineProto
     */
    public function denormalizeOutgoingMessage(array $message): array
    {
        $result = [
            'peer' => null,
            'message' => $message['text'] ?? '',
            'silent' => $message['silent'] ?? false,
            'parse_mode' => 'HTML',
        ];

        // Извлекаем chat_id
        $chatId = $message['chat_id'] ?? '';
        if ($chatId) {
            $result['peer'] = $this->extractTelegramId($chatId);
        }

        // Добавляем медиа если есть
        if (isset($message['media'])) {
            $result['media'] = $message['media'];
        }

        return $result;
    }

    /**
     * Получить тип мессенджера
     */
    public function getType(): string
    {
        return 'madelineproto';
    }

    /**
     * Скачать файл во временный файл
     */
    private function downloadFile(string $url, string $type): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mdl_' . $type . '_');
        
        if (!$tempFile) {
            $this->logger->error('MadelineProtoMessenger: failed to create temp file');
            return null;
        }

        try {
            $ch = curl_init($url);
            $fp = fopen($tempFile, 'wb');
            
            if (!$fp) {
                @unlink($tempFile);
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'BitrixTelegram/MadelineProto',
            ]);

            $success = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (!$success) {
                $this->logger->error('MadelineProtoMessenger: failed to download file', [
                    'url' => $url,
                    'error' => $error,
                ]);
                @unlink($tempFile);
                return null;
            }

            $this->logger->debug('MadelineProtoMessenger: file downloaded', [
                'url' => $url,
                'temp_file' => $tempFile,
                'size' => filesize($tempFile),
            ]);

            return $tempFile;
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: exception downloading file', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            
            return null;
        }
    }

    /**
     * Извлечь чистый Telegram ID из chat_id
     */
    private function extractTelegramId(string $chatId): string
    {
        // Убираем префиксы
        $prefixes = ['tguser_', 'tguser_chat_', 'tguser_channel_'];
        
        foreach ($prefixes as $prefix) {
            if (strpos($chatId, $prefix) === 0) {
                return substr($chatId, strlen($prefix));
            }
        }
        
        return $chatId;
    }

    /**
     * Получить список доступных сессий для текущего домена
     */
    public function getAvailableSessions(): array
    {
        if (!$this->domain) {
            return [];
        }

        try {
            return $this->madelineProtoService->getDomainSessions($this->domain);
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to get available sessions', [
                'domain' => $this->domain,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Проверить, активна ли сессия
     */
    public function isSessionActive(): bool
    {
        if (!$this->domain || !$this->sessionId) {
            return false;
        }

        try {
            $mp = $this->madelineProtoService->getInstance($this->domain, $this->sessionId);
            if (!$mp) {
                return false;
            }

            $me = $mp->getSelf();
            return (bool) $me;
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: error checking session activity', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Перезагрузить сессию (если есть проблемы)
     */
    public function reloadSession(): bool
    {
        if (!$this->domain || !$this->sessionId) {
            return false;
        }

        try {
            // Очищаем кеш в сервисе (если есть доступ)
            // Или просто пробуем получить новый инстанс
            $mp = $this->madelineProtoService->getInstance($this->domain, $this->sessionId);
            return (bool) $mp;
        } catch (Exception $e) {
            $this->logger->error('MadelineProtoMessenger: failed to reload session', [
                'domain' => $this->domain,
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}