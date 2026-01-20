<?php

declare(strict_types=1);

namespace BitrixTelegram\Helpers;

class MessageDetector
{
    public const SOURCE_BITRIX = 'bitrix';
    public const SOURCE_TELEGRAM = 'telegram';
    public const SOURCE_MAX = 'max';
    public const SOURCE_UNKNOWN = 'unknown';

    /**
     * Определяет источник сообщения по структуре данных
     */
    public function detectSource(array $data): string
    {
        // Проверяем Bitrix24
        if ($this->isBitrixMessage($data)) {
            return self::SOURCE_BITRIX;
        }

        // Проверяем Telegram
        if ($this->isTelegramMessage($data)) {
            return self::SOURCE_TELEGRAM;
        }

        // Проверяем Max
        if ($this->isMaxMessage($data)) {
            return self::SOURCE_MAX;
        }

        return self::SOURCE_UNKNOWN;
    }

    /**
     * Проверяет, является ли сообщение от Bitrix24
     */
    private function isBitrixMessage(array $data): bool
    {
        // Bitrix24 отправляет event и data с CONNECTOR
        if (!empty($data['event']) && $data['event'] === 'ONIMCONNECTORMESSAGEADD') {
            return true;
        }

        // Или PLACEMENT для настроек
        if (!empty($data['PLACEMENT']) && $data['PLACEMENT'] === 'SETTING_CONNECTOR') {
            return true;
        }

        // Проверка на наличие auth от Bitrix
        if (!empty($data['auth']['domain']) && !empty($data['auth']['access_token'])) {
            return true;
        }

        return false;
    }

    /**
     * Проверяет, является ли сообщение от Telegram
     */
    private function isTelegramMessage(array $data): bool
    {
        // Telegram webhook всегда содержит update_id
        if (isset($data['update_id'])) {
            return true;
        }

        // Или message с from и chat (характерная структура Telegram)
        if (isset($data['message']['from']['id']) && 
            isset($data['message']['chat']['id']) &&
            isset($data['message']['message_id'])) {
            return true;
        }

        return false;
    }

    /**
     * Проверяет, является ли сообщение от Max
     */
    private function isMaxMessage(array $data): bool
    {
        if(isset($data['message']['recipient'])) {
            return true;
        }

        return false;
    }

    /**
     * Определяет тип события Bitrix24
     */
    public function detectBitrixEventType(array $data): ?string
    {
        if (!empty($data['event'])) {
            return $data['event'];
        }

        if (!empty($data['PLACEMENT'])) {
            return 'PLACEMENT_' . $data['PLACEMENT'];
        }

        return null;
    }

    /**
     * Определяет тип сообщения (текст, фото, документ и т.д.)
     */
    public function detectMessageType(array $message, string $source): string
    {
        switch ($source) {
            case self::SOURCE_TELEGRAM:
                return $this->detectTelegramMessageType($message);
            
            case self::SOURCE_MAX:
                return $this->detectMaxMessageType($message);
            
            case self::SOURCE_BITRIX:
                return $this->detectBitrixMessageType($message);
            
            default:
                return 'unknown';
        }
    }

    private function detectTelegramMessageType(array $message): string
    {
        if (isset($message['photo'])) return 'photo';
        if (isset($message['document'])) return 'document';
        if (isset($message['voice'])) return 'voice';
        if (isset($message['video'])) return 'video';
        if (isset($message['audio'])) return 'audio';
        if (isset($message['sticker'])) return 'sticker';
        if (isset($message['location'])) return 'location';
        if (isset($message['contact'])) return 'contact';
        if (isset($message['text'])) return 'text';
        
        return 'unknown';
    }

    private function detectMaxMessageType(array $message): string
    {
        if (isset($message['type'])) {
            return $message['type'];
        }

        if (isset($message['file_url'])) return 'file';
        if (isset($message['image_url'])) return 'image';
        if (isset($message['text'])) return 'text';
        
        return 'unknown';
    }

    private function detectBitrixMessageType(array $message): string
    {
        if (!isset($message['message'])) {
            return 'unknown';
        }

        $msg = $message['message'];

        if (isset($msg['files']) && !empty($msg['files'])) {
            $file = $msg['files'][0];
            return $file['type'] ?? 'file';
        }

        if (isset($msg['text'])) return 'text';

        return 'unknown';
    }

    /**
     * Извлекает chat_id в зависимости от источника
     */
    public function extractChatId(array $data, string $source): ?string
    {
        switch ($source) {
            case self::SOURCE_TELEGRAM:
                return (string) ($data['message']['chat']['id'] ?? 
                                $data['chat']['id'] ?? null);
            
            case self::SOURCE_MAX:
                return $data['message']['chat_id'] ?? 
                       $data['chat']['id'] ?? 
                       $data['chat_id'] ?? null;
            
            case self::SOURCE_BITRIX:
                return $data['data']['MESSAGES'][0]['chat']['id'] ?? 
                       $data['chat']['id'] ?? null;
            
            default:
                return null;
        }
    }

    /**
     * Проверяет, является ли chat_id от Max
     */
    public function isMaxChatId(string $chatId): bool
    {
        return strpos($chatId, 'max_') === 0;
    }

    /**
     * Проверяет, является ли это reply сообщение
     */
    public function isReplyMessage(array $message, string $source): bool
    {
        if ($source === self::SOURCE_TELEGRAM) {
            return isset($message['reply_to_message']);
        }

        if ($source === self::SOURCE_MAX) {
            return isset($message['reply_to_id']) || isset($message['quoted_message']);
        }

        return false;
    }
}