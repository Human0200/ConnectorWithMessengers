<?php

declare(strict_types=1);

namespace BitrixTelegram\Handlers;

use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Services\TelegramBotService;
use BitrixTelegram\Messengers\MessengerFactory;
use BitrixTelegram\Messengers\MessengerInterface;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Helpers\Logger;

/**
 * Обрабатывает исходящие сообщения из Bitrix24 → мессенджер.
 *
 * Маршрутизация по префиксу chat_id:
 *  tgbot_  → Telegram Bot API (TelegramBotService)
 *  max_    → Max (MaxMessenger → MaxService: upload + send)
 *  tguser_ → Telegram User (MadelineProtoMessenger)
 *  tg_     → Telegram (TelegramMessenger)
 */
class BitrixToMessengerHandler implements IncomingHandlerInterface
{
    public function __construct(
        private BitrixService      $bitrixService,
        private TelegramBotService $telegramBotService,
        private MessengerFactory   $messengerFactory,
        private TokenRepository    $tokenRepository,
        private ChatRepository     $chatRepository,
        private Logger             $logger
    ) {}

    public function handle(array $data): array
    {
        $this->logger->info('BitrixToMessengerHandler: incoming', ['data' => $data]);

        if (empty($data['data']['CONNECTOR']) || empty($data['data']['MESSAGES'])) {
            return ['status' => 'error', 'message' => 'Invalid data'];
        }

        $connectorId = $data['data']['CONNECTOR'];
        $domain      = $data['auth']['domain'] ?? '';

        foreach ($data['data']['MESSAGES'] as $message) {
            $this->dispatchMessage($message, $connectorId, $domain, $data);
        }

        return ['status' => 'ok', 'action' => 'bitrix_to_messenger'];
    }

    // ─── Маршрутизация одного сообщения ──────────────────────────

    private function dispatchMessage(array $message, string $connectorId, string $domain, array $originalData): void
    {
        $bitrixChatId  = $message['chat']['id'];
        $messengerType = $this->detectMessengerType($bitrixChatId);
        $chatId        = $this->stripPrefix($bitrixChatId, $messengerType);

        $text  = isset($message['message']['text'])
            ? $this->cleanText($message['message']['text'])
            : '';
        $files = $message['message']['files'] ?? [];

        $success = match ($messengerType) {
            'telegram_bot' => $this->sendViaTelegramBot($chatId, $text, $files),
            'max'          => $this->sendViaMax($chatId, $text, $files, $domain, $originalData),
            default        => $this->sendViaMessengerAdapter($messengerType, $chatId, $text, $files, $domain, $originalData),
        };

        $this->confirmDelivery($connectorId, $message, $bitrixChatId, $domain, $success);
    }

    // ─── Telegram Bot API ─────────────────────────────────────────

    private function sendViaTelegramBot(string $chatId, string $text, array $files): bool
    {
        $botToken = $this->chatRepository->getBotTokenByChatId($chatId);

        $this->logger->info('TgBot send: token lookup', [
            'chatId'       => $chatId,
            'token_found'  => !empty($botToken),
            'files_count'  => count($files),
            'has_text'     => !empty($text),
        ]);

        if (!$botToken) {
            $this->logger->error('BitrixToMessengerHandler: Telegram bot token not found', [
                'chatId' => $chatId,
            ]);
            return false;
        }

        $result = ['ok' => false];

        foreach ($files as $file) {
            $fileUrl  = $file['downloadLink'] ?? $file['link'] ?? '';
            $fileType = $file['type'] ?? '';

            $this->logger->info('TgBot send: sending file', [
                'chatId'   => $chatId,
                'fileType' => $fileType,
                'fileUrl'  => $fileUrl ? mb_substr($fileUrl, 0, 80) : 'empty',
            ]);

            if (!$fileUrl) continue;

            $origFileName = $file['name'] ?? $file['file_name'] ?? null;

            $this->logger->info('TgBot send: origFileName', ['origFileName' => $origFileName, 'file_keys' => array_keys($file)]);

            if ($fileType === 'image') {
                $result = $this->telegramBotService->sendPhoto($botToken, $chatId, $fileUrl, $text);
                $text   = '';
            } else {
                $result = $this->telegramBotService->sendDocument($botToken, $chatId, $fileUrl, $text, $origFileName);
                $text   = '';
            }

            $this->logger->info('TgBot send: file result', ['result' => $result]);
        }

        if ($text) {
            $result = $this->telegramBotService->sendMessage($botToken, $chatId, $text);
            $this->logger->info('TgBot send: text result', ['result' => $result]);
        }

        return !empty($result['ok']);
    }

    // ─── Max (через MaxMessenger → MaxService) ────────────────────

    /**
     * Отправка в Max:
     * — изображения: MaxService::sendImage() — отправляет по URL напрямую
     * — файлы/видео/аудио: MaxService::sendFile/sendVideo/sendAudio() — загружает на Max, затем отправляет по токену
     * — текст: MaxService::sendMessage()
     */
    private function sendViaMax(string $chatId, string $text, array $files, string $domain, array $originalData): bool
    {
        $maxUserId = $this->chatRepository->getMaxUserIdForChat($chatId, $domain);

        if (!$maxUserId) {
            $this->logger->error('BitrixToMessengerHandler: Max user_id not found', [
                'chatId' => $chatId,
                'domain' => $domain,
            ]);
            return false;
        }

        // Токен из профиля (user_messenger_profiles.token) — не ищем в bitrix_integration_tokens
        $maxToken = $this->chatRepository->getMaxTokenByChatId($chatId, $domain);

        if (!$maxToken) {
            $this->logger->error('BitrixToMessengerHandler: Max profile token not found', [
                'chatId' => $chatId,
                'domain' => $domain,
            ]);
            return false;
        }

        $messenger = $this->messengerFactory->create('max');
        $messenger->setDomain($domain);
        if (method_exists($messenger, 'setToken')) {
            $messenger->setToken($maxToken);
        }

        $result = ['ok' => false];

        foreach ($files as $index => $file) {
            $fileUrl  = $file['downloadLink'] ?? $file['link'] ?? '';
            $fileType = $file['type'] ?? '';

            if (!$fileUrl) continue;

            // Оригинальное имя файла из данных Bitrix24
            $originalFile = $originalData['data']['MESSAGES'][0]['message']['files'][$index] ?? $file;
            $fileName     = $originalFile['name'] ?? $originalFile['file_name'] ?? null;
            $fileData     = $fileName ? ['name' => $fileName] : null;

            if ($fileType === 'image') {
                $result = $messenger->sendPhoto($maxUserId, $fileUrl, $text);
            } else {
                $result = $messenger->sendDocument($maxUserId, $fileUrl, $text, $fileData);
            }

            $text = ''; // подпись уже передана с первым вложением
        }

        if ($text) {
            $result = $messenger->sendMessage($maxUserId, $text);
        }

        return !empty($result['ok']) || !empty($result['success']);
    }

    // ─── Прочие мессенджеры (через MessengerInterface) ────────────

    private function sendViaMessengerAdapter(
        string $messengerType,
        string $chatId,
        string $text,
        array $files,
        string $domain,
        array $originalData
    ): bool {
        $chatInfo = $this->chatRepository->getChatInfo($messengerType, $chatId);

        if (!$chatInfo) {
            $this->logger->warning('BitrixToMessengerHandler: chat info not found', [
                'type'   => $messengerType,
                'chatId' => $chatId,
            ]);
            return false;
        }

        $messenger = $this->messengerFactory->create($messengerType);

        if (method_exists($messenger, 'setDomain')) {
            $messenger->setDomain($domain);
        }

        $recipientId = $this->resolveRecipientId($messengerType, $chatId, $chatInfo, $domain, $messenger);

        if (!$recipientId) {
            $this->logger->error('BitrixToMessengerHandler: recipient_id not resolved', [
                'type'   => $messengerType,
                'chatId' => $chatId,
            ]);
            return false;
        }

        $result = ['ok' => false];

        foreach ($files as $index => $file) {
            $fileUrl  = $file['downloadLink'] ?? $file['link'] ?? '';
            $fileType = $file['type'] ?? '';

            if (!$fileUrl) continue;

            $fileData = $originalData['data']['MESSAGES'][0]['message']['files'][$index] ?? null;

            if ($fileType === 'image') {
                $result = $messenger->sendPhoto($recipientId, $fileUrl, $text);
                $text   = '';
            } else {
                $result = $messenger->sendDocument($recipientId, $fileUrl, $text, $fileData);
                $text   = '';
            }
        }

        if ($text) {
            $result = $messenger->sendMessage($recipientId, $text);
        }

        return !empty($result['ok']) || !empty($result['success']);
    }

    private function resolveRecipientId(
        string $messengerType,
        string $chatId,
        array $chatInfo,
        string $domain,
        MessengerInterface $messenger
    ): ?string {
        if ($messengerType === 'telegram_user') {
            $profileId = $chatInfo['profile_id'] ?? null;
            $sessionId = $chatInfo['session_id'] ?? null;
            if ($profileId && $sessionId && method_exists($messenger, 'setProfileSession')) {
                $messenger->setProfileSession((int) $profileId, $sessionId);
            }
        }

        return $chatId;
    }

    // ─── Подтверждение доставки ───────────────────────────────────

    private function confirmDelivery(
        string $connectorId,
        array $message,
        string $bitrixChatId,
        string $domain,
        bool $success
    ): void {
        try {
            $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);
            if (!$lineId) return;

            $statusMessages = [[
                'im'      => $message['im'] ?? '0',
                'message' => [
                    'id' => is_array($message['message']['id'] ?? null)
                        ? $message['message']['id']
                        : [$message['message']['id'] ?? '0'],
                ],
                'chat'    => ['id' => $bitrixChatId],
            ]];

            if ($success) {
                $this->bitrixService->sendDeliveryStatus($connectorId, $lineId, $statusMessages, $domain);
            } else {
                $this->bitrixService->sendErrorStatus($connectorId, $lineId, $statusMessages, $domain);
            }
        } catch (\Exception $e) {
            $this->logger->error('BitrixToMessengerHandler: delivery confirmation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─── Вспомогательные методы ───────────────────────────────────

    private function detectMessengerType(string $chatId): string
    {
        if (str_starts_with($chatId, 'tgbot_'))  return 'telegram_bot';
        if (str_starts_with($chatId, 'max_'))    return 'max';
        if (str_starts_with($chatId, 'tguser_')) return 'telegram_user';
        if (str_starts_with($chatId, 'tg_') || str_starts_with($chatId, 'telegram_')) return 'telegram';
        return 'telegram';
    }

    private function stripPrefix(string $chatId, string $messengerType): string
    {
        $prefixes = [
            'telegram_bot'  => ['tgbot_'],
            'telegram'      => ['tg_', 'telegram_'],
            'telegram_user' => ['tguser_'],
            'max'           => ['max_'],
        ];

        foreach ($prefixes[$messengerType] ?? [] as $prefix) {
            if (str_starts_with($chatId, $prefix)) {
                return substr($chatId, strlen($prefix));
            }
        }

        return $chatId;
    }

    private function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\[(\w+)\](.*?)\[\/\1\]/s', '$2', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace(['[br]', '[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]', '[s]', '[/s]'], '', $text);
        return trim($text, " :-\t\n\r\0\x0B");
    }
}