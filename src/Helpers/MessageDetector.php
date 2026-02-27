<?php

declare(strict_types=1);

namespace BitrixTelegram\Helpers;

class MessageDetector
{
    public const SOURCE_BITRIX        = 'bitrix';
    public const SOURCE_TELEGRAM      = 'telegram';
    public const SOURCE_TELEGRAM_BOT  = 'telegram_bot';  // Bot API webhook (?bot_token=xxx в URL)
    public const SOURCE_TELEGRAM_USER = 'telegram_user'; // от listen_sessions.php / session_worker.php
    public const SOURCE_MAX            = 'max';
    public const SOURCE_UNKNOWN        = 'unknown';

    /**
     * Определяет источник сообщения по структуре данных.
     *
     * Порядок важен:
     *  1. Bitrix24
     *  2. Telegram Bot  — ?bot_token=xxx в URL ИЛИ update_id в теле
     *  3. Telegram User — поле profile_id (от listen_sessions.php)
     *  4. Max
     */
    public function detectSource(array $data): string
    {
        if ($this->isBitrixMessage($data)) {
            return self::SOURCE_BITRIX;
        }

        // Проверяем Bot ДО telegram_user — update_id не должен попасть в telegram_user
        if ($this->isTelegramBotMessage($data)) {
            return self::SOURCE_TELEGRAM_BOT;
        }

        if (isset($data['profile_id']) || isset($data['session_name'])) {
            return self::SOURCE_TELEGRAM_USER;
        }

        // Проверяем Max Bot ДО стандартного isMaxMessage
        if ($this->isMaxBotMessage($data)) {
            return self::SOURCE_MAX;
        }

        if ($this->isMaxMessage($data)) {
            return self::SOURCE_MAX;
        }

        return self::SOURCE_UNKNOWN;
    }

    private function isBitrixMessage(array $data): bool
    {
        if (!empty($data['event']) && $data['event'] === 'ONIMCONNECTORMESSAGEADD') {
            return true;
        }
        if (!empty($data['PLACEMENT']) && $data['PLACEMENT'] === 'SETTING_CONNECTOR') {
            return true;
        }
        if (!empty($data['auth']['domain']) && !empty($data['auth']['access_token'])) {
            return true;
        }
        return false;
    }

    /**
     * Telegram Bot API webhook.
     * Признак A: ?bot_token=xxx в URL — зашиваем при setWebhook в ProfileController.
     * Признак B: update_id в теле — стандарт Bot API.
     */
    private function isTelegramBotMessage(array $data): bool
    {
        if (!empty($_GET['bot_token'])) {
            return true;
        }

        if (isset($data['update_id'])) {
            return true;
        }

        if (
            isset($data['message']['from']['id']) &&
            isset($data['message']['chat']['id']) &&
            isset($data['message']['message_id'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * Max Bot профильный webhook.
     * Признак: ?max_token=xxx в URL — зашивается при setWebhook в ProfileController.
     */
    private function isMaxBotMessage(array $data): bool
    {
        if (!empty($_GET['max_token'])) {
            return true;
        }
        return false;
    }

    private function isMaxMessage(array $data): bool
    {
        return isset($data['message']['recipient']);
    }

    public function detectBitrixEventType(array $data): ?string
    {
        if (!empty($data['event']))     return $data['event'];
        if (!empty($data['PLACEMENT'])) return 'PLACEMENT_' . $data['PLACEMENT'];
        return null;
    }

    public function detectMessageType(array $message, string $source): string
    {
        switch ($source) {
            case self::SOURCE_TELEGRAM:
            case self::SOURCE_TELEGRAM_BOT:
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
        if (isset($message['photo']))    return 'photo';
        if (isset($message['document'])) return 'document';
        if (isset($message['voice']))    return 'voice';
        if (isset($message['video']))    return 'video';
        if (isset($message['audio']))    return 'audio';
        if (isset($message['sticker']))  return 'sticker';
        if (isset($message['location'])) return 'location';
        if (isset($message['contact']))  return 'contact';
        if (isset($message['text']))     return 'text';
        return 'unknown';
    }

    private function detectMaxMessageType(array $message): string
    {
        if (isset($message['type']))      return $message['type'];
        if (isset($message['file_url']))  return 'file';
        if (isset($message['image_url'])) return 'image';
        if (isset($message['text']))      return 'text';
        return 'unknown';
    }

    private function detectBitrixMessageType(array $message): string
    {
        if (!isset($message['message'])) return 'unknown';
        $msg = $message['message'];
        if (isset($msg['files']) && !empty($msg['files'])) {
            return $msg['files'][0]['type'] ?? 'file';
        }
        if (isset($msg['text'])) return 'text';
        return 'unknown';
    }

    public function extractChatId(array $data, string $source): ?string
    {
        switch ($source) {
            case self::SOURCE_TELEGRAM:
            case self::SOURCE_TELEGRAM_BOT:
                return (string)($data['message']['chat']['id'] ?? $data['chat']['id'] ?? null);
            case self::SOURCE_MAX:
                return $data['message']['chat_id'] ?? $data['chat']['id'] ?? $data['chat_id'] ?? null;
            case self::SOURCE_BITRIX:
                return $data['data']['MESSAGES'][0]['chat']['id'] ?? $data['chat']['id'] ?? null;
            default:
                return null;
        }
    }

    public function isMaxChatId(string $chatId): bool
    {
        return strpos($chatId, 'max_') === 0;
    }

    public function isReplyMessage(array $message, string $source): bool
    {
        if (in_array($source, [self::SOURCE_TELEGRAM, self::SOURCE_TELEGRAM_BOT], true)) {
            return isset($message['reply_to_message']);
        }
        if ($source === self::SOURCE_MAX) {
            return isset($message['reply_to_id']) || isset($message['quoted_message']);
        }
        return false;
    }
}