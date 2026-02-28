<?php

declare(strict_types=1);

namespace BitrixTelegram\Handlers;

use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Services\TelegramBotService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Helpers\Logger;

/**
 * Обрабатывает входящие сообщения от Telegram Bot API.
 * Токен бота берётся из параметра URL ?bot_token=...
 */
class TelegramBotIncomingHandler implements IncomingHandlerInterface
{
    public function __construct(
        private BitrixService     $bitrixService,
        private TelegramBotService $telegramBotService,
        private TokenRepository   $tokenRepository,
        private ProfileRepository $profileRepository,
        private ChatRepository    $chatRepository,
        private Logger            $logger
    ) {}

    public function handle(array $data): array
    {
        $botToken = $_GET['bot_token'] ?? null;

        if (empty($botToken)) {
            $this->logger->warning('TelegramBotHandler: bot_token missing in URL');
            return ['status' => 'ok', 'message' => 'bot_token missing'];
        }

        $profile = $this->profileRepository->findActiveByTokenAndType($botToken, 'telegram_bot');

        if (!$profile) {
            $this->logger->error('TelegramBotHandler: profile not found', [
                'token_prefix' => substr($botToken, 0, 10) . '...',
            ]);
            return ['status' => 'ok', 'message' => 'profile not found'];
        }

        $profileId = (int) $profile['id'];

        $message = $data['message']
            ?? $data['edited_message']
            ?? $data['callback_query']['message']
            ?? null;

        if (!$message) {
            return ['status' => 'ok', 'action' => 'non_message_update'];
        }

        $chatId   = (string) ($message['chat']['id'] ?? '');
        $from     = $message['from'] ?? [];
        $userId   = (string) ($from['id'] ?? '');
        $userName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''))
            ?: ($from['username'] ?? 'Unknown');
        $text     = $message['text'] ?? $message['caption'] ?? '';

        if (empty($chatId)) {
            return ['status' => 'error', 'message' => 'chat_id not found'];
        }

        $this->logger->info('TelegramBotHandler: incoming', [
            'profile_id' => $profileId,
            'chat_id'    => $chatId,
            'user'       => $userName,
            'text'       => mb_substr($text, 0, 50),
        ]);

        $domain = $this->profileRepository->getDomainByProfileId($profileId);

        if (!$domain) {
            $this->logger->error('TelegramBotHandler: no domain for profile', ['profile_id' => $profileId]);
            $this->telegramBotService->sendMessage(
                $botToken,
                $chatId,
                "⚠️ <b>Интеграция не настроена</b>\n\nПривяжите профиль к домену Bitrix24 в личном кабинете."
            );
            return ['status' => 'error', 'message' => 'Domain not configured'];
        }

        $connectorId = $this->tokenRepository->getConnectorId($domain, 'telegram_bot');

        if (!$connectorId) {
            $this->logger->error('TelegramBotHandler: no connector', [
                'profile_id' => $profileId,
                'domain'     => $domain,
            ]);
            return ['status' => 'error', 'message' => 'Connector not found'];
        }

        $this->chatRepository->saveConnection('telegram_bot', $chatId, $domain, $connectorId, $userName, $userId);
        $this->chatRepository->updateProfileId('telegram_bot', $chatId, $profileId);

        $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);

        if (!$lineId) {
            $this->telegramBotService->sendMessage(
                $botToken,
                $chatId,
                "⚠️ <b>Открытая линия не настроена</b>\n\nНастройте открытую линию в Bitrix24."
            );
            return ['status' => 'error', 'message' => 'Line not configured'];
        }

        $bitrixMsg = [
            'user'    => ['id' => $chatId, 'name' => $userName],
            'message' => ['date' => time()],
            'chat'    => ['id' => 'tgbot_' . $chatId],
        ];

        $files = $this->telegramBotService->extractFiles($message, $botToken);
        if (!empty($files)) {
            $bitrixMsg['message']['files'] = $files;
        }
        if (!empty($text)) {
            $bitrixMsg['message']['text'] = $text;
        }

        if (!empty($bitrixMsg['message']['text']) || !empty($bitrixMsg['message']['files'])) {
            $result = $this->bitrixService->sendMessages($connectorId, $lineId, [$bitrixMsg], $domain);
            if (empty($result['result'])) {
                $this->logger->error('TelegramBotHandler: failed to send to Bitrix24', ['result' => $result]);
            }
        }

        return ['status' => 'ok', 'action' => 'telegram_bot_message_sent'];
    }
}