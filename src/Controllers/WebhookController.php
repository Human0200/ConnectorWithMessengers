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

            case MessageDetector::SOURCE_TELEGRAM_USER:
                return $this->handleMessengerToBitrix($data, $source);

            case MessageDetector::SOURCE_MAX:
                return $this->handleMessengerToBitrix($data, $source);

            default:
                $this->logger->warning('Unknown webhook source', ['data' => $data]);
                return ['status' => 'error', 'message' => 'Unknown source'];
        }
    }

    // ─── Bitrix → Messenger ───────────────────────────────────────

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
            $messenger     = $this->messengerFactory->create($messengerType);
            $chatId        = $this->cleanChatId($bitrixChatId, $messengerType);

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
                // Для Telegram User нужно установить сессию из профиля
                $profileId = $chatInfo['profile_id'] ?? null;
                $sessionId = $chatInfo['session_id'] ?? null;
                if ($profileId && $sessionId && method_exists($messenger, 'setProfileSession')) {
                    $messenger->setProfileSession((int)$profileId, $sessionId);
                }
                $recipientId = $chatId;
            } else {
                $recipientId = $chatId;
            }

            $text  = $message['message']['text'] ?? '';
            if ($text) {
                $text = $this->cleanTextForMessenger($text);
            }

            $files  = $message['message']['files'] ?? [];
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

    // ─── Messenger → Bitrix ───────────────────────────────────────

    private function handleMessengerToBitrix(array $data, string $source): array
    {
        $this->logger->info('Messenger to Bitrix', ['source' => $source, 'data' => $data]);

        try {
            $messenger      = $this->messengerFactory->create($source);
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

    // ─── Telegram User ────────────────────────────────────────────

    private function handleTelegramMessenger(
        array $rawData,
        ?string $domain,
        string $chatId,
        MessengerInterface $messenger,
        string $userName,
        mixed $userId,
        array $normalizedMessage
    ): array {
        // Берём profile_id и session_id из payload (отправляет listen_sessions.php)
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
            // Первое сообщение — ищем домен через profile_id
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

    /**
     * Найти домен по profile_id через связку:
     * user_messenger_profiles → user (user_id) → tokens (domain)
     */
    private function getDomainByProfileId(int $profileId): ?string
    {
        $stmt = $this->profileRepository->getPdo()->prepare("
            SELECT bit.domain
            FROM user_messenger_profiles ump
            JOIN bitrix_integration_tokens bit ON bit.user_id = ump.user_id
            WHERE ump.id = ?
              AND bit.domain IS NOT NULL
              AND bit.domain != ''
              AND bit.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$profileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row['domain'] ?? null;
    }

    // ─── Max ──────────────────────────────────────────────────────

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

    // ─── Общая обработка ─────────────────────────────────────────

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

    // ─── Helpers ──────────────────────────────────────────────────

    private function prepareMessagesForBitrix(array $normalized, MessengerInterface $messenger, string $chatId, string $userName): array
    {
        $messages    = [];
        $messengerType = $messenger->getType();
        $mainMessage = $this->createBitrixMessage($chatId, $userName, null, $messengerType);

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
        $prefixes = ['telegram' => 'tg_', 'max' => 'max_'];
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

    private function getMaxUserIdForChat(string $chatId, string $domain): ?string
    {
        $chatInfo = $this->chatRepository->getChatInfo('max', $chatId);
        if ($chatInfo && !empty($chatInfo['user_id'])) {
            return $chatInfo['user_id'];
        }
        return $chatId;
    }

    private function detectMessengerTypeFromChatId(string $chatId): string
    {
        if (str_starts_with($chatId, 'tg_') || str_starts_with($chatId, 'telegram_')) return 'telegram';
        if (str_starts_with($chatId, 'max_')) return 'max';
        return 'telegram';
    }

    private function cleanChatId(string $chatId, string $messengerType): string
    {
        $prefixes = ['telegram' => ['tg_', 'telegram_'], 'max' => ['max_']];
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