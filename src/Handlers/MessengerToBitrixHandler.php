<?php

declare(strict_types=1);

namespace BitrixTelegram\Handlers;

use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Messengers\MessengerFactory;
use BitrixTelegram\Messengers\MessengerInterface;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Helpers\Logger;

/**
 * Обрабатывает входящие сообщения от любого мессенджера (не Bot API) → Bitrix24.
 * Использует MessengerInterface::normalizeIncomingMessage() для унификации данных.
 */
class MessengerToBitrixHandler implements IncomingHandlerInterface
{
    public function __construct(
        private BitrixService     $bitrixService,
        private MessengerFactory  $messengerFactory,
        private TokenRepository   $tokenRepository,
        private ProfileRepository $profileRepository,
        private ChatRepository    $chatRepository,
        private Logger            $logger,
        private string            $messengerType
    ) {}

    public function handle(array $data): array
    {
        $this->logger->info('MessengerToBitrixHandler: incoming', [
            'type' => $this->messengerType,
        ]);

        try {
            $messenger         = $this->messengerFactory->create($this->messengerType);
            $normalizedMessage = $messenger->normalizeIncomingMessage($data);

            $chatId   = $normalizedMessage['chat_id'] ?? null;
            $userName = $normalizedMessage['user_name'] ?? 'Unknown';
            $userId   = $normalizedMessage['user_id'] ?? null;

            $this->logger->info('MessengerToBitrixHandler: normalized', [
                'chatId'   => $chatId,
                'userName' => $userName,
                'text'     => $normalizedMessage['text'] ?? '',
            ]);

            if (!$chatId) {
                $this->logger->error('MessengerToBitrixHandler: chat_id missing');
                return ['status' => 'error', 'message' => 'Chat ID not found'];
            }

            $domain = $this->chatRepository->getDomainByMessengerChat($this->messengerType, $chatId);

            return match ($this->messengerType) {
                'max'           => $this->handleMax($domain, $chatId, $messenger, $userName, $userId, $normalizedMessage),
                'telegram_user' => $this->handleTelegramUser($data, $domain, $chatId, $messenger, $userName, $userId, $normalizedMessage),
                default         => $this->handleGeneric($domain, $chatId, $messenger, $userName, $normalizedMessage),
            };

        } catch (\Exception $e) {
            $this->logger->error('MessengerToBitrixHandler: exception', [
                'type'  => $this->messengerType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ─── Telegram User (MadelineProto) ────────────────────────────

    private function handleTelegramUser(
        array $rawData,
        ?string $domain,
        string $chatId,
        MessengerInterface $messenger,
        string $userName,
        mixed $userId,
        array $normalizedMessage
    ): array {
        $profileId = isset($rawData['profile_id']) ? (int) $rawData['profile_id'] : null;
        $sessionId = $rawData['session_id'] ?? null;

        if (!$profileId || !$sessionId) {
            $this->logger->error('TelegramUserHandler: profile_id or session_id missing');
            return ['status' => 'error', 'message' => 'profile_id or session_id missing'];
        }

        // Устанавливаем контекст сессии в мессенджер
        if (method_exists($messenger, 'setProfileSession')) {
            $messenger->setProfileSession($profileId, $sessionId);
        }

        if (!$domain) {
            $domain = $this->profileRepository->getDomainByProfileId($profileId);

            if (!$domain) {
                $this->logger->error('TelegramUserHandler: no domain for profile', ['profile_id' => $profileId]);
                return ['status' => 'error', 'message' => 'Domain not configured for this profile'];
            }

            $connectorId = $this->tokenRepository->getConnectorId($domain, 'telegram_user');

            if (!$connectorId) {
                $this->logger->error('TelegramUserHandler: no connector', ['domain' => $domain]);
                return ['status' => 'error', 'message' => 'Telegram User connector not configured'];
            }

            $this->chatRepository->saveConnection(
                'telegram_user',
                $chatId,
                $domain,
                $connectorId,
                $userName,
                (string) $userId
            );
        }

        return $this->processAndForwardToBitrix($domain, $chatId, $messenger, $userName, $normalizedMessage);
    }

    // ─── Max (пользовательский аккаунт) ──────────────────────────

    private function handleMax(
        ?string $domain,
        string $chatId,
        MessengerInterface $messenger,
        string $userName,
        mixed $userId,
        array $normalizedMessage
    ): array {
        if (!$domain) {
            $domainsWithTokens = $this->tokenRepository->findActiveDomainsWithMaxToken();

            if (empty($domainsWithTokens)) {
                $this->logger->error('MaxHandler: no domains with Max token');
                $messenger->sendMessage($chatId, "⚠️ <b>Интеграция не настроена!</b>\n\nНастройте токен Max в приложении Bitrix24.");
                return ['status' => 'error', 'message' => 'No Max token configured'];
            }

            $domains = array_values($domainsWithTokens);
            $domain  = $domains[0];

            if (count($domains) > 1) {
                $this->logger->warning('MaxHandler: multiple domains, using first', [
                    'selected'  => $domain,
                    'available' => $domains,
                ]);
            }

            $connectorId = $this->tokenRepository->getConnectorId($domain, 'max');
            $this->chatRepository->saveConnection('max', $chatId, $domain, $connectorId, $userName, $userId);
            $messenger->sendMessage($chatId, "✅ <b>Соединение установлено!</b>\n\n🌐 <b>Домен:</b> $domain");
        }

        $this->chatRepository->fillMaxProfileId($chatId, $domain);

        return $this->processAndForwardToBitrix($domain, $chatId, $messenger, $userName, $normalizedMessage);
    }

    // ─── Прочие мессенджеры ───────────────────────────────────────

    private function handleGeneric(
        ?string $domain,
        string $chatId,
        MessengerInterface $messenger,
        string $userName,
        array $normalizedMessage
    ): array {
        if (!$domain) {
            return ['status' => 'ok', 'action' => 'no_domain'];
        }
        return $this->processAndForwardToBitrix($domain, $chatId, $messenger, $userName, $normalizedMessage);
    }

    // ─── Общий пайплайн: собрать сообщение → отправить в Bitrix24 ─

    private function processAndForwardToBitrix(
        string $domain,
        string $chatId,
        MessengerInterface $messenger,
        string $userName,
        array $normalizedMessage
    ): array {
        $connectorId = $this->tokenRepository->getConnectorId($domain, $this->messengerType);

        if (!$connectorId) {
            $this->logger->error('processAndForwardToBitrix: connector not found', [
                'domain' => $domain,
                'type'   => $this->messengerType,
            ]);
            return ['status' => 'error', 'message' => 'Connector not found'];
        }

        $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);

        if (!$lineId) {
            $messenger->sendMessage($chatId, "⚠️ <b>Открытая линия не настроена!</b>\n\nНастройте открытую линию в Bitrix24.");
            return ['status' => 'error', 'message' => 'Line not configured'];
        }

        $messagesToSend = $this->buildBitrixMessages($normalizedMessage, $messenger, $chatId, $userName);

        if (!empty($messagesToSend)) {
            $result = $this->bitrixService->sendMessages($connectorId, $lineId, $messagesToSend, $domain);
            if (empty($result['result'])) {
                $messenger->sendMessage($chatId, "❌ <b>Ошибка отправки сообщения в Bitrix24</b>");
                $this->logger->error('processAndForwardToBitrix: Bitrix24 rejected message', ['result' => $result]);
            }
        }

        return ['status' => 'ok', 'action' => 'message_sent', 'source' => $this->messengerType];
    }

    /**
     * Собрать массив сообщений в формате Bitrix24 из нормализованного сообщения мессенджера.
     */
    private function buildBitrixMessages(
        array $normalized,
        MessengerInterface $messenger,
        string $chatId,
        string $userName
    ): array {
        $prefixMap = [
            'telegram'      => 'tg_',
            'max'           => 'max_',
            'telegram_bot'  => 'tgbot_',
            'telegram_user' => 'tguser_',
        ];
        $prefix = $prefixMap[$this->messengerType] ?? ($this->messengerType . '_');

        $bitrixMsg = [
            'user'    => ['id' => $chatId, 'name' => $userName],
            'message' => ['date' => time()],
            'chat'    => ['id' => $prefix . $chatId],
        ];

        if (!empty($normalized['files'])) {
            $bitrixMsg['message']['files'] = [];
            foreach ($normalized['files'] as $file) {
                $fileUrl = $this->resolveFileUrl($file, $messenger);
                if (!$fileUrl) continue;

                // Имя файла: берём из нескольких мест по приоритету,
                // включая raw-данные вложения (актуально для Max)
                $fileName = $file['filename']
                    ?? $normalized['raw']['body']['attachments'][0]['filename']
                    ?? $file['name']
                    ?? $file['file_name']
                    ?? 'file';

                $bitrixMsg['message']['files'][] = [
                    'url'  => $fileUrl,
                    'name' => $fileName,
                    'type' => $this->mapFileType($file['type'] ?? 'file'),
                ];
            }
        }

        if (!empty($normalized['text'])) {
            $bitrixMsg['message']['text'] = $normalized['text'];
        }

        $hasContent = !empty($bitrixMsg['message']['text']) || !empty($bitrixMsg['message']['files']);

        return $hasContent ? [$bitrixMsg] : [];
    }

    private function resolveFileUrl(array $file, MessengerInterface $messenger): ?string
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
        return match (strtolower($type)) {
            'photo', 'image' => 'image',
            'voice', 'audio' => 'audio',
            'video'          => 'video',
            default          => 'file',
        };
    }
}