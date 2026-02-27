<?php

declare(strict_types=1);

namespace BitrixTelegram\Controllers;

use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Helpers\BBCodeConverter;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Helpers\MessageDetector;
use BitrixTelegram\Messengers\MessengerFactory;
use BitrixTelegram\Messengers\MessengerInterface;

class WebhookController
{
    public function __construct(
        private BitrixService    $bitrixService,
        private MessengerFactory $messengerFactory,
        private TokenRepository  $tokenRepository,
        private ProfileRepository $profileRepository,
        private ChatRepository   $chatRepository,
        private BBCodeConverter  $bbConverter,
        private Logger           $logger,
        private MessageDetector  $detector
    ) {}

    public function handleWebhook(array $data): array
    {
        $source = $this->detector->detectSource($data);

        $this->logger->info('Webhook received', [
            'source'   => $source,
            'has_data' => !empty($data),
        ]);

        switch ($source) {
            case MessageDetector::SOURCE_BITRIX:
                return $this->handleBitrixToMessenger($data);

            case MessageDetector::SOURCE_TELEGRAM_BOT:
                return $this->handleTelegramBotIncoming($data);

            case MessageDetector::SOURCE_TELEGRAM_USER:
                return $this->handleMessengerToBitrix($data, $source);

            case MessageDetector::SOURCE_MAX:
                // Если в URL есть ?max_token= — профильный бот, иначе старый путь (доменный)
                if (!empty($_GET['max_token'])) {
                    return $this->handleMaxBotIncoming($data);
                }
                return $this->handleMessengerToBitrix($data, $source);

            default:
                $this->logger->warning('Unknown webhook source', ['data' => $data]);
                return ['status' => 'error', 'message' => 'Unknown source'];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  Telegram Bot → Bitrix24
    //
    //  Telegram шлёт POST на /webhook.php?bot_token=TOKEN
    //  Мы читаем токен из URL, ищем профиль, находим домен, отправляем в Bitrix.
    // ═══════════════════════════════════════════════════════════════

    private function handleTelegramBotIncoming(array $data): array
    {
        // Токен бота — из URL (?bot_token=xxx), зашит при setWebhook в ProfileController
        $botToken = $_GET['bot_token'] ?? null;

        if (empty($botToken)) {
            $this->logger->warning('telegram_bot: bot_token missing in URL');
            return ['status' => 'ok', 'message' => 'bot_token missing'];
        }

        // Ищем профиль в user_messenger_profiles по токену
        $profile = $this->findProfileByToken($botToken, 'telegram_bot');

        if (!$profile) {
            $this->logger->error('telegram_bot: profile not found', [
                'token_prefix' => substr($botToken, 0, 10) . '...',
            ]);
            // Возвращаем 200 чтобы Telegram не повторял запрос
            return ['status' => 'ok', 'message' => 'profile not found'];
        }

        $profileId = (int)$profile['id'];

        // Извлекаем message из Bot API update
        $message = $data['message']
            ?? $data['edited_message']
            ?? $data['callback_query']['message']
            ?? null;

        if (!$message) {
            return ['status' => 'ok', 'action' => 'non_message_update'];
        }

        $chatId   = (string)($message['chat']['id'] ?? '');
        $from     = $message['from'] ?? [];
        $userId   = (string)($from['id'] ?? '');
        $userName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''))
            ?: ($from['username'] ?? 'Unknown');
        $text     = $message['text'] ?? $message['caption'] ?? '';

        if (empty($chatId)) {
            return ['status' => 'error', 'message' => 'chat_id not found'];
        }

        $this->logger->info('telegram_bot incoming', [
            'profile_id' => $profileId,
            'chat_id'    => $chatId,
            'user'       => $userName,
            'text'       => mb_substr($text, 0, 50),
        ]);

        // Ищем домен привязанный к профилю (через profile_bitrix_connections)
        $domain = $this->getDomainByProfileId($profileId);

        if (!$domain) {
            $this->logger->error('telegram_bot: no domain for profile', ['profile_id' => $profileId]);
            $this->sendBotMessage($botToken, $chatId,
                "⚠️ <b>Интеграция не настроена</b>\n\n" .
                "Привяжите этот профиль к домену Bitrix24 в личном кабинете."
            );
            return ['status' => 'error', 'message' => 'Domain not configured'];
        }

        $connectorId = $this->tokenRepository->getConnectorId($domain, 'telegram_bot');

        if (!$connectorId) {
            $this->logger->error('telegram_bot: no connector', [
                'profile_id' => $profileId,
                'domain'     => $domain,
            ]);
            return ['status' => 'error', 'message' => 'Connector not found'];
        }

        // Сохраняем связку чат ↔ домен ↔ профиль в messenger_chat_connections
        // profile_id нужен при ответе из Bitrix — чтобы знать через какого бота отвечать
        $this->chatRepository->saveConnection(
            'telegram_bot',
            $chatId,
            $domain,
            $connectorId,
            $userName,
            $userId
        );
        // Отдельно сохраняем profile_id (метод saveConnection его не принимает — дописываем UPDATE)
        $this->saveProfileIdForChat('telegram_bot', $chatId, $profileId);

        $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);

        if (!$lineId) {
            $this->sendBotMessage($botToken, $chatId,
                "⚠️ <b>Открытая линия не настроена</b>\n\nНастройте открытую линию в Bitrix24."
            );
            return ['status' => 'error', 'message' => 'Line not configured'];
        }

        // Формируем сообщение для Bitrix24.
        // Префикс tgbot_ в chat.id — по нему при ответе из Bitrix определяем тип мессенджера.
        $bitrixMsg = [
            'user'    => ['id' => $chatId, 'name' => $userName],
            'message' => ['date' => time()],
            'chat'    => ['id' => 'tgbot_' . $chatId],
        ];

        // Файлы: получаем URL через Bot API getFile
        $files = $this->extractBotFiles($message, $botToken);
        if (!empty($files)) {
            $bitrixMsg['message']['files'] = $files;
        }
        if (!empty($text)) {
            $bitrixMsg['message']['text'] = $text;
        }

        if (!empty($bitrixMsg['message']['text']) || !empty($bitrixMsg['message']['files'])) {
            $result = $this->bitrixService->sendMessages($connectorId, $lineId, [$bitrixMsg], $domain);
            if (empty($result['result'])) {
                $this->logger->error('telegram_bot: failed to send to Bitrix24', ['result' => $result]);
            }
        }

        return ['status' => 'ok', 'action' => 'telegram_bot_message_sent'];
    }

    // ═══════════════════════════════════════════════════════════════
    //  Max Bot → Bitrix24  (профильный, по аналогии с telegram_bot)
    //
    //  Max шлёт POST на /webhook.php?max_token=TOKEN
    //  Читаем токен из URL, ищем профиль, находим домен, отправляем в Bitrix.
    // ═══════════════════════════════════════════════════════════════

    private function handleMaxBotIncoming(array $data): array
    {
        $maxToken = $_GET['max_token'] ?? null;

        if (empty($maxToken)) {
            $this->logger->warning('max_bot: max_token missing in URL');
            return ['status' => 'ok', 'message' => 'max_token missing'];
        }

        // Ищем профиль в user_messenger_profiles по токену
        $profile = $this->findProfileByToken($maxToken, 'max');

        if (!$profile) {
            $this->logger->error('max_bot: profile not found', [
                'token_prefix' => substr($maxToken, 0, 10) . '...',
            ]);
            return ['status' => 'ok', 'message' => 'profile not found'];
        }

        $profileId = (int)$profile['id'];

        // Max присылает: { "update_type": "message_created", "message": { ... } }
        $message = $data['message'] ?? null;

        if (!$message) {
            return ['status' => 'ok', 'action' => 'non_message_update'];
        }

        // В Max chat_id = sender.user_id (личные чаты) или chat.chat_id (групповые)
        $chatId   = (string)($message['sender']['user_id']
            ?? $message['chat_id']
            ?? $message['recipient']['chat_id']
            ?? '');
        $userId   = (string)($message['sender']['user_id'] ?? '');
        $userName = $message['sender']['name'] ?? $message['sender']['username'] ?? 'Unknown';
        $text     = $message['body']['text'] ?? '';

        if (empty($chatId)) {
            return ['status' => 'error', 'message' => 'chat_id not found'];
        }

        $this->logger->info('max_bot incoming', [
            'profile_id' => $profileId,
            'chat_id'    => $chatId,
            'user'       => $userName,
            'text'       => mb_substr($text, 0, 50),
        ]);

        // Ищем домен привязанный к профилю
        $domain = $this->getDomainByProfileId($profileId);

        if (!$domain) {
            $this->logger->error('max_bot: no domain for profile', ['profile_id' => $profileId]);
            // У Max нет sendMessage без домена — просто логируем
            return ['status' => 'error', 'message' => 'Domain not configured'];
        }

        $connectorId = $this->tokenRepository->getConnectorId($domain, 'max');

        if (!$connectorId) {
            $this->logger->error('max_bot: no connector', [
                'profile_id' => $profileId,
                'domain'     => $domain,
            ]);
            return ['status' => 'error', 'message' => 'Connector not found'];
        }

        // Сохраняем связку чат ↔ домен ↔ профиль
        $this->chatRepository->saveConnection('max', $chatId, $domain, $connectorId, $userName, $userId);
        $this->saveProfileIdForChat('max', $chatId, $profileId);

        $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);

        if (!$lineId) {
            return ['status' => 'error', 'message' => 'Line not configured'];
        }

        // Собираем файлы из вложений Max
        $files = [];
        foreach (($message['body']['attachments'] ?? []) as $attachment) {
            $type    = $attachment['type'] ?? 'file';
            $payload = $attachment['payload'] ?? [];
            $fileUrl = $payload['url'] ?? '';
            if ($fileUrl) {
                $files[] = [
                    'url'  => $fileUrl,
                    'name' => $payload['name'] ?? 'file',
                    'type' => in_array($type, ['image', 'photo']) ? 'image' : 'file',
                ];
            }
        }

        $bitrixMsg = [
            'user'    => ['id' => $chatId, 'name' => $userName],
            'message' => ['date' => time()],
            'chat'    => ['id' => 'max_' . $chatId],
        ];

        if (!empty($files)) {
            $bitrixMsg['message']['files'] = $files;
        }
        if (!empty($text)) {
            $bitrixMsg['message']['text'] = $text;
        }

        if (!empty($bitrixMsg['message']['text']) || !empty($bitrixMsg['message']['files'])) {
            $result = $this->bitrixService->sendMessages($connectorId, $lineId, [$bitrixMsg], $domain);
            if (empty($result['result'])) {
                $this->logger->error('max_bot: failed to send to Bitrix24', ['result' => $result]);
            }
        }

        return ['status' => 'ok', 'action' => 'max_bot_message_sent'];
    }

    // ═══════════════════════════════════════════════════════════════
    //  Bitrix24 → Messenger
    // ═══════════════════════════════════════════════════════════════

    private function handleBitrixToMessenger(array $data): array
    {
        $this->logger->info('Bitrix to Messenger', ['data' => $data]);

        if (empty($data['data']['CONNECTOR']) || empty($data['data']['MESSAGES'])) {
            return ['status' => 'error', 'message' => 'Invalid data'];
        }

        $connectorId = $data['data']['CONNECTOR'];
        $domain      = $data['auth']['domain'] ?? '';

        foreach ($data['data']['MESSAGES'] as $message) {
            $bitrixChatId  = $message['chat']['id'];
            $messengerType = $this->detectMessengerTypeFromChatId($bitrixChatId);
            $chatId        = $this->cleanChatId($bitrixChatId, $messengerType);

            $text  = $message['message']['text'] ?? '';
            if ($text) {
                $text = $this->cleanTextForMessenger($text);
            }
            $files = $message['message']['files'] ?? [];

            // ── Ответ через Max API (профильный токен) ────────────
            if ($messengerType === 'max') {
                $maxToken = $this->getMaxTokenByChatId($chatId);

                if ($maxToken) {
                    // Профильный бот — отправляем напрямую через токен из профиля
                    $maxUserId = $this->getMaxUserIdForChat($chatId, $domain);
                    if (!$maxUserId) {
                        $this->logger->error('max_bot: user_id not found for chat', ['chatId' => $chatId]);
                        $this->sendDeliveryConfirmation($connectorId, $message, $bitrixChatId, $domain, false);
                        continue;
                    }

                    $result = ['success' => false];
                    foreach ($files as $file) {
                        $fileType = $file['type'] ?? '';
                        $fileUrl  = $file['downloadLink'] ?? $file['link'] ?? '';
                        if ($fileUrl) {
                            // Max не умеет слать файлы по URL напрямую без upload — отправляем ссылкой в тексте
                            $text = trim($text . "\n" . $fileUrl);
                        }
                    }
                    if ($text) {
                        $result = $this->sendMaxBotMessage($maxToken, $maxUserId, $text);
                    }

                    $this->sendDeliveryConfirmation($connectorId, $message, $bitrixChatId, $domain, !empty($result['success']));
                    continue;
                }

                // Fallback: старый доменный путь (если profile_id не найден)
            }

            // ── Ответ через Telegram Bot API ──────────────────────
            if ($messengerType === 'telegram_bot') {
                // Находим токен бота через profile_id из messenger_chat_connections
                $botToken = $this->getBotTokenByChatId($chatId);

                if (!$botToken) {
                    $this->logger->error('telegram_bot: token not found for chat', ['chatId' => $chatId]);
                    continue;
                }

                $result = ['ok' => false];
                foreach ($files as $file) {
                    $fileUrl = $file['downloadLink'] ?? $file['link'] ?? '';
                    if (($file['type'] ?? '') === 'image' && $fileUrl) {
                        $result = $this->sendBotPhoto($botToken, $chatId, $fileUrl, $text);
                        $text   = '';
                    } elseif ($fileUrl) {
                        $result = $this->sendBotDocument($botToken, $chatId, $fileUrl, $text);
                        $text   = '';
                    }
                }
                if ($text) {
                    $result = $this->sendBotMessage($botToken, $chatId, $text);
                }

                $this->sendDeliveryConfirmation($connectorId, $message, $bitrixChatId, $domain, !empty($result['ok']));
                continue;
            }

            // ── Прочие мессенджеры (telegram_user, max) — без изменений ──
            $messenger = $this->messengerFactory->create($messengerType);

            if ($messengerType === 'max' && method_exists($messenger, 'setDomain')) {
                $messenger->setDomain($domain);
            }

            $chatInfo = $this->chatRepository->getChatInfo($messengerType, $chatId);
            if (!$chatInfo) {
                $this->logger->warning('Chat not found or not connected', [
                    'messengerType' => $messengerType,
                    'chatId'        => $chatId,
                    'bitrixChatId'  => $bitrixChatId,
                ]);
                continue;
            }

            if ($messengerType === 'max') {
                $maxUserId = $this->getMaxUserIdForChat($chatId, $domain);
                if (!$maxUserId) {
                    $this->logger->error('Max user_id not found for chat', ['chatId' => $chatId]);
                    continue;
                }
                $recipientId = $maxUserId;
            } elseif ($messengerType === 'telegram_user') {
                $profileId = $chatInfo['profile_id'] ?? null;
                $sessionId = $chatInfo['session_id'] ?? null;
                if ($profileId && $sessionId && method_exists($messenger, 'setProfileSession')) {
                    $messenger->setProfileSession((int)$profileId, $sessionId);
                }
                $recipientId = $chatId;
            } else {
                $recipientId = $chatId;
            }

            $result = ['ok' => false];
            foreach ($files as $file) {
                $fileType = $file['type'] ?? '';
                $fileUrl  = $file['downloadLink'] ?? $file['link'] ?? '';
                if ($fileType === 'image' && $fileUrl) {
                    $result = $messenger->sendPhoto($recipientId, $fileUrl, $text);
                    $text   = '';
                } elseif ($fileUrl) {
                    $fileData = $data['data']['MESSAGES'][0]['message']['files'][0];
                    $result   = $messenger->sendDocument($recipientId, $fileUrl, $text, $fileData);
                    $text     = '';
                }
            }
            if ($text) {
                $result = $messenger->sendMessage($recipientId, $text);
            }

            $this->sendDeliveryConfirmation(
                $connectorId,
                $message,
                $bitrixChatId,
                $domain,
                !empty($result['ok']) || !empty($result['success'])
            );
        }

        return ['status' => 'ok', 'action' => 'bitrix_to_messenger'];
    }

    // ═══════════════════════════════════════════════════════════════
    //  Messenger → Bitrix (telegram_user, max) — без изменений
    // ═══════════════════════════════════════════════════════════════

    private function handleMessengerToBitrix(array $data, string $source): array
    {
        $this->logger->info('Messenger to Bitrix', ['source' => $source, 'data' => $data]);

        try {
            $messenger         = $this->messengerFactory->create($source);
            $normalizedMessage = $messenger->normalizeIncomingMessage($data);

            $chatId   = $normalizedMessage['chat_id'] ?? null;
            $userName = $normalizedMessage['user_name'] ?? 'Unknown';
            $userId   = $normalizedMessage['user_id'] ?? null;

            $this->logger->info('Normalized message', [
                'chatId'   => $chatId,
                'userName' => $userName,
                'userId'   => $userId,
                'text'     => $normalizedMessage['text'] ?? '',
            ]);

            if (!$chatId) {
                $this->logger->error('Chat ID not found', ['message' => $normalizedMessage]);
                return ['status' => 'error', 'message' => 'Chat ID not found'];
            }

            $domain = $this->chatRepository->getDomainByMessengerChat($source, $chatId);

            switch ($source) {
                case 'max':
                    return $this->handleMaxMessenger($domain, $chatId, $messenger, $userName, $userId, $normalizedMessage);
                case 'telegram_user':
                    return $this->handleTelegramMessenger($data, $domain, $chatId, $messenger, $userName, $userId, $normalizedMessage);
                default:
                    return $this->handleOtherMessenger($domain, $chatId, $messenger, $userName, $userId, $normalizedMessage['text'] ?? '', $source);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error in handleMessengerToBitrix', [
                'error'  => $e->getMessage(),
                'source' => $source,
                'trace'  => $e->getTraceAsString(),
            ]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function handleTelegramMessenger(
        array $rawData,
        ?string $domain,
        string $chatId,
        MessengerInterface $messenger,
        string $userName,
        mixed $userId,
        array $normalizedMessage
    ): array {
        $profileId = isset($rawData['profile_id']) ? (int)$rawData['profile_id'] : null;
        $sessionId = $rawData['session_id'] ?? null;

        $this->logger->info('handleTelegramMessenger', [
            'profile_id' => $profileId,
            'session_id' => $sessionId,
            'domain'     => $domain,
            'chatId'     => $chatId,
        ]);

        if (!$profileId || !$sessionId) {
            $this->logger->error('profile_id or session_id missing in telegram_user webhook', [
                'data_keys' => array_keys($rawData),
            ]);
            return ['status' => 'error', 'message' => 'profile_id or session_id missing'];
        }

        if (!$domain) {
            $domain = $this->getDomainByProfileId($profileId);

            if (!$domain) {
                $this->logger->error('Domain not found for profile_id', ['profile_id' => $profileId]);
                return ['status' => 'error', 'message' => 'Domain not configured for this profile'];
            }

            $connectorId = $this->tokenRepository->getConnectorId($domain, 'telegram_user');

            if (!$connectorId) {
                $this->logger->error('No connector for telegram_user', ['domain' => $domain]);
                return ['status' => 'error', 'message' => 'Telegram User connector not configured'];
            }

            $this->chatRepository->saveConnection(
                'telegram_user',
                $chatId,
                $domain,
                $connectorId,
                $userName,
                (string)$userId,
            );

            $this->logger->info('Created new Telegram User connection', [
                'profile_id'  => $profileId,
                'session_id'  => $sessionId,
                'chatId'      => $chatId,
                'domain'      => $domain,
                'connectorId' => $connectorId,
            ]);
        }

        return $this->processMessengerMessage($domain, 'telegram_user', $chatId, $messenger, $userName, $normalizedMessage);
    }

    private function handleMaxMessenger($domain, $chatId, $messenger, $userName, $userId, $normalizedMessage): array
    {
        if (!$domain) {
            $domainsWithTokens = $this->tokenRepository->findActiveDomainsWithMaxToken();
            if (empty($domainsWithTokens)) {
                $this->logger->error('No domains with Max token found');
                $messenger->sendMessage($chatId, "⚠️ <b>Интеграция не настроена!</b>\n\nНастройте токен Max в Bitrix24 приложении.");
                return ['status' => 'error', 'message' => 'No Max token configured'];
            }

            $domains = array_values($domainsWithTokens);
            $domain  = $domains[0];

            if (count($domains) > 1) {
                $this->logger->warning('Multiple domains with Max token, using first', [
                    'selected'  => $domain,
                    'available' => $domains,
                ]);
            }

            $connectorId = $this->tokenRepository->getConnectorId($domain, 'max');
            $this->chatRepository->saveConnection('max', $chatId, $domain, $connectorId, $userName, $userId);
            $messenger->sendMessage($chatId, "✅ <b>Соединение установлено!</b>\n\n🌐 <b>Домен:</b> $domain\nТеперь ваши сообщения будут отправляться в Bitrix24.");
        }

        return $this->processMessengerMessage($domain, 'max', $chatId, $messenger, $userName, $normalizedMessage);
    }

    private function handleOtherMessenger($domain, $chatId, $messenger, $userName, $userId, $text, $source): array
    {
        if (!$domain) {
            if ($text) {
                $this->processBotCommand($source, $chatId, $text, $messenger, $userName, $userId);
            }
            return ['status' => 'ok', 'action' => 'no_domain'];
        }
        return $this->processMessengerMessage($domain, $source, $chatId, $messenger, $userName, []);
    }

    private function processMessengerMessage($domain, $source, $chatId, $messenger, $userName, $normalizedMessage): array
    {
        $connectorId = $this->tokenRepository->getConnectorId($domain, $source);

        if (!$connectorId) {
            $this->logger->error('Connector ID not found', ['domain' => $domain, 'source' => $source]);
            return ['status' => 'error', 'message' => 'Connector not found'];
        }

        $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);

        if (!$lineId) {
            $messenger->sendMessage($chatId, "⚠️ <b>Открытая линия не настроена!</b>\n\nНастройте открытую линию в Bitrix24.");
            return ['status' => 'error', 'message' => 'Line not configured'];
        }

        $messagesToSend = $this->prepareMessagesForBitrix($normalizedMessage, $messenger, $chatId, $userName);

        if (!empty($messagesToSend)) {
            $result = $this->bitrixService->sendMessages($connectorId, $lineId, $messagesToSend, $domain);
            if (empty($result['result'])) {
                $messenger->sendMessage($chatId, "❌ <b>Ошибка отправки сообщения в Bitrix24</b>");
                $this->logger->error('Failed to send message to Bitrix24', ['result' => $result]);
            }
        }

        return ['status' => 'ok', 'action' => 'message_sent', 'source' => $source];
    }

    // ═══════════════════════════════════════════════════════════════
    //  Telegram Bot API — отправка
    // ═══════════════════════════════════════════════════════════════

    private function sendBotMessage(string $token, string $chatId, string $text): array
    {
        return $this->callBotApi($token, 'sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    private function sendBotPhoto(string $token, string $chatId, string $photoUrl, string $caption = ''): array
    {
        return $this->callBotApi($token, 'sendPhoto', [
            'chat_id'    => $chatId,
            'photo'      => $photoUrl,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    private function sendBotDocument(string $token, string $chatId, string $fileUrl, string $caption = ''): array
    {
        return $this->callBotApi($token, 'sendDocument', [
            'chat_id'    => $chatId,
            'document'   => $fileUrl,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    private function callBotApi(string $token, string $method, array $params): array
    {
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true) ?? ['ok' => false];
    }

    /**
     * Извлечь файлы из Bot API message и получить их URL через getFile.
     */
    private function extractBotFiles(array $message, string $botToken): array
    {
        $files  = [];
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

        if ($fileId) {
            $fileInfo = $this->callBotApi($botToken, 'getFile', ['file_id' => $fileId]);
            if (!empty($fileInfo['ok']) && !empty($fileInfo['result']['file_path'])) {
                $url      = 'https://api.telegram.org/file/bot' . $botToken . '/' . $fileInfo['result']['file_path'];
                $files[]  = ['url' => $url, 'name' => $name, 'type' => $type];
            }
        }

        return $files;
    }

    // ═══════════════════════════════════════════════════════════════
    //  DB helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Найти профиль в user_messenger_profiles по токену и типу.
     */
    private function findProfileByToken(string $token, string $messengerType): ?array
    {
        $stmt = $this->profileRepository->getPdo()->prepare("
            SELECT *
            FROM user_messenger_profiles
            WHERE token          = ?
              AND messenger_type = ?
              AND is_active      = 1
            LIMIT 1
        ");
        $stmt->execute([$token, $messengerType]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Найти домен по profile_id.
     * Смотрим в profile_bitrix_connections — там saveConnection() сохраняет связку.
     */
    private function getDomainByProfileId(int $profileId): ?string
    {
        $stmt = $this->profileRepository->getPdo()->prepare("
            SELECT domain
            FROM profile_bitrix_connections
            WHERE profile_id = ?
              AND is_active   = 1
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$profileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row) return $row['domain'];

        // Fallback: через user_id — один домен на пользователя
        $stmt2 = $this->profileRepository->getPdo()->prepare("
            SELECT bit.domain
            FROM user_messenger_profiles ump
            JOIN bitrix_integration_tokens bit ON bit.user_id = ump.user_id
            WHERE ump.id        = ?
              AND bit.domain    IS NOT NULL
              AND bit.domain    != ''
              AND bit.is_active = 1
            LIMIT 1
        ");
        $stmt2->execute([$profileId]);
        $row2 = $stmt2->fetch(\PDO::FETCH_ASSOC);

        return $row2['domain'] ?? null;
    }

    /**
     * Сохранить profile_id в messenger_chat_connections для telegram_bot чата.
     * Нужно при ответе из Bitrix24 — чтобы знать через какого бота отвечать.
     */
    private function saveProfileIdForChat(string $messengerType, string $chatId, int $profileId): void
    {
        // Таблица messenger_chat_connections создаётся в ChatRepository.
        // Добавляем колонку profile_id если её ещё нет (один раз, потом закешируется).
        try {
            $this->profileRepository->getPdo()->prepare("
                UPDATE messenger_chat_connections
                SET profile_id = ?
                WHERE messenger_type = ? AND messenger_chat_id = ?
            ")->execute([$profileId, $messengerType, $chatId]);
        } catch (\Throwable $e) {
            // Если колонки profile_id нет — добавляем
            try {
                $this->profileRepository->getPdo()->exec(
                    "ALTER TABLE messenger_chat_connections ADD COLUMN IF NOT EXISTS profile_id INT NULL"
                );
                $this->profileRepository->getPdo()->prepare("
                    UPDATE messenger_chat_connections
                    SET profile_id = ?
                    WHERE messenger_type = ? AND messenger_chat_id = ?
                ")->execute([$profileId, $messengerType, $chatId]);
            } catch (\Throwable $e2) {
                $this->logger->error('saveProfileIdForChat failed', ['error' => $e2->getMessage()]);
            }
        }
    }

    /**
     * Получить токен бота по chat_id.
     * Берём profile_id из messenger_chat_connections, потом token из user_messenger_profiles.
     */
    private function getBotTokenByChatId(string $chatId): ?string
    {
        $stmt = $this->profileRepository->getPdo()->prepare("
            SELECT ump.token
            FROM messenger_chat_connections mcc
            JOIN user_messenger_profiles ump ON ump.id = mcc.profile_id
            WHERE mcc.messenger_type    = 'telegram_bot'
              AND mcc.messenger_chat_id = ?
              AND mcc.is_active         = 1
              AND ump.is_active         = 1
            LIMIT 1
        ");
        $stmt->execute([$chatId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row['token'] ?? null;
    }

    // ═══════════════════════════════════════════════════════════════
    //  Helpers — без изменений относительно оригинала
    // ═══════════════════════════════════════════════════════════════

    private function prepareMessagesForBitrix(array $normalized, MessengerInterface $messenger, string $chatId, string $userName): array
    {
        $messages      = [];
        $messengerType = $messenger->getType();
        $mainMessage   = $this->createBitrixMessage($chatId, $userName, null, $messengerType);

        $this->logger->info('Preparing message for Bitrix', ['normalized' => $normalized]);

        if (!empty($normalized['files'])) {
            $mainMessage['message']['files'] = [];
            foreach ($normalized['files'] as $file) {
                $fileUrl = $this->getFileUrl($file, $messenger);
                if ($fileUrl) {
                    $fileName = $file['filename']
                        ?? $normalized['raw']['body']['attachments'][0]['filename']
                        ?? $file['name']
                        ?? 'file';
                    $mainMessage['message']['files'][] = [
                        'url'  => $fileUrl,
                        'name' => $fileName,
                        'type' => $this->mapFileType($file['type'] ?? 'file'),
                    ];
                }
            }
            if (!empty($normalized['text'])) {
                $mainMessage['message']['text'] = $normalized['text'];
            }
        } elseif (!empty($normalized['text'])) {
            $mainMessage['message']['text'] = $normalized['text'];
        }

        if (!empty($mainMessage['message']['text']) || !empty($mainMessage['message']['files'])) {
            $messages[] = $mainMessage;
        }

        return $messages;
    }

    private function createBitrixMessage(string $chatId, string $userName, ?string $text = null, string $messengerType = 'telegram'): array
    {
        $prefixes = ['telegram' => 'tg_', 'max' => 'max_', 'telegram_bot' => 'tgbot_', 'telegram_user' => 'tguser_'];
        $prefix   = $prefixes[$messengerType] ?? $messengerType . '_';

        $message = [
            'user'    => ['id' => $chatId, 'name' => $userName],
            'message' => ['date' => time()],
            'chat'    => ['id' => $prefix . $chatId],
        ];

        if ($text !== null) {
            $message['message']['text'] = $text;
        }

        return $message;
    }

    private function getFileUrl(array $file, MessengerInterface $messenger): ?string
    {
        if (!empty($file['url'])) return $file['url'];
        if (!empty($file['id'])) {
            $fileInfo = $messenger->getFile($file['id']);
            if ($fileInfo && isset($fileInfo['file_path'])) {
                return $messenger->getFileUrl($fileInfo['file_path']);
            }
        }
        return null;
    }

    private function mapFileType(string $type): string
    {
        $mapping = [
            'photo' => 'image', 'image' => 'image', 'document' => 'file',
            'voice' => 'audio', 'video' => 'video', 'audio'    => 'audio', 'file' => 'file',
        ];
        return $mapping[strtolower($type)] ?? 'file';
    }

    private function cleanTextForMessenger(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\[(\w+)\](.*?)\[\/\1\]/s', '$2', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace(['[br]', '[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]', '[s]', '[/s]'], '', $text);
        return trim($text, " :-\t\n\r\0\x0B");
    }

    /**
     * Получить токен Max профиля по chat_id.
     * Берём profile_id из messenger_chat_connections, потом token из user_messenger_profiles.
     */
    private function getMaxTokenByChatId(string $chatId): ?string
    {
        $stmt = $this->profileRepository->getPdo()->prepare("
            SELECT ump.token
            FROM messenger_chat_connections mcc
            JOIN user_messenger_profiles ump ON ump.id = mcc.profile_id
            WHERE mcc.messenger_type    = 'max'
              AND mcc.messenger_chat_id = ?
              AND mcc.is_active         = 1
              AND ump.is_active         = 1
            LIMIT 1
        ");
        $stmt->execute([$chatId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row['token'] ?? null;
    }

    /**
     * Отправить сообщение через Max Bot API (профильный токен)
     */
    private function sendMaxBotMessage(string $token, string $userId, string $text): array
    {
        $url = 'https://platform-api.max.ru/messages?user_id=' . urlencode($userId);
        $ch  = curl_init($url);
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
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error('max_bot sendMessage cURL error', ['error' => $error]);
            return ['success' => false];
        }

        return ['success' => $httpCode === 200, 'response' => json_decode($response, true)];
    }

    private function getMaxUserIdForChat(string $chatId, string $domain): ?string
    {
        $chatInfo = $this->chatRepository->getChatInfo('max', $chatId);
        if ($chatInfo && !empty($chatInfo['user_id'])) {
            return $chatInfo['user_id'];
        }
        return $chatId;
    }

    /**
     * По префиксу chat.id из Bitrix24 определяем тип мессенджера.
     * tgbot_ → telegram_bot (НОВЫЙ)
     * tg_ / telegram_ → telegram (старый)
     * max_ → max
     */
    private function detectMessengerTypeFromChatId(string $chatId): string
    {
        if (str_starts_with($chatId, 'tgbot_'))  return 'telegram_bot';
        if (str_starts_with($chatId, 'tg_') || str_starts_with($chatId, 'telegram_')) return 'telegram';
        if (str_starts_with($chatId, 'max_'))    return 'max';
        return 'telegram';
    }

    private function cleanChatId(string $chatId, string $messengerType): string
    {
        $prefixes = [
            'telegram_bot' => ['tgbot_'],
            'telegram'     => ['tg_', 'telegram_'],
            'max'          => ['max_'],
        ];
        foreach ($prefixes[$messengerType] ?? [] as $prefix) {
            if (str_starts_with($chatId, $prefix)) {
                return substr($chatId, strlen($prefix));
            }
        }
        return $chatId;
    }

    private function sendDeliveryConfirmation(string $connectorId, array $message, string $bitrixChatId, string $domain, bool $success): void
    {
        try {
            $statusMessages = [[
                'im'      => $message['im'] ?? '0',
                'message' => ['id' => is_array($message['message']['id'] ?? null) ? $message['message']['id'] : [$message['message']['id'] ?? '0']],
                'chat'    => ['id' => $bitrixChatId],
            ]];

            $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);
            if (!$lineId) return;

            if ($success) {
                $this->bitrixService->sendDeliveryStatus($connectorId, $lineId, $statusMessages, $domain);
            } else {
                $this->bitrixService->sendErrorStatus($connectorId, $lineId, $statusMessages, $domain);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error sending delivery confirmation', ['error' => $e->getMessage()]);
        }
    }

    private function processBotCommand(string $messengerType, string $chatId, string $text, MessengerInterface $messenger, string $userName = '', string $userId = ''): void
    {
        $text = trim($text);
        if (!str_starts_with($text, '/')) return;

        $command = strtolower(trim($text, '/'));

        switch ($command) {
            case 'start':
                $msg = "👋 <b>Добро пожаловать, $userName!</b>\n\nДля привязки аккаунта отправьте ваш домен Bitrix24.\nПример: <code>mydomain.bitrix24.ru</code>";
                break;
            case 'help':
                $msg = "🆘 <b>Доступные команды:</b>\n\n/start - Начать работу\n/help - Показать справку\n/status - Проверить статус привязки";
                break;
            case 'status':
                $chatInfo = $this->chatRepository->getChatInfo($messengerType, $chatId);
                if ($chatInfo && $chatInfo['is_active']) {
                    $domain      = $chatInfo['domain'];
                    $connectorId = $this->tokenRepository->getConnectorId($domain, $messengerType);
                    $msg = "✅ <b>Аккаунт привязан</b>\n\n🌐 <b>Домен:</b> $domain\n🤖 <b>Мессенджер:</b> " . ucfirst($messengerType) . "\n🆔 <b>Connector ID:</b> " . ($connectorId ?? 'не найден');
                } else {
                    $msg = "❌ <b>Аккаунт не привязан</b>\n\nОтправьте ваш домен Bitrix24 для привязки.";
                }
                break;
            default:
                if ($this->isValidDomain($text)) {
                    $domain      = $text;
                    $connectorId = $this->tokenRepository->getConnectorId($domain, $messengerType);
                    if ($connectorId) {
                        $this->chatRepository->saveConnection($messengerType, $chatId, $domain, $connectorId, $userName, $userId);
                        $msg = "✅ <b>Домен успешно привязан!</b>\n\n🌐 <b>Домен:</b> $domain";
                    } else {
                        $msg = "❌ <b>Домен не найден!</b>\n\nСначала установите приложение в Bitrix24 на домене: $domain";
                    }
                } else {
                    $msg = "❌ <b>Неизвестная команда</b>\n\nИспользуйте /help для просмотра доступных команд.";
                }
                break;
        }

        $messenger->sendMessage($chatId, $msg);
    }

    private function isValidDomain(string $domain): bool
    {
        $domain = trim(preg_replace('/^https?:\/\//', '', $domain));
        return preg_match('/^[a-zA-Z0-9.-]+\.bitrix24\.(ru|com|by|kz|ua|su)$/', $domain) === 1;
    }
}