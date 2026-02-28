<?php

declare(strict_types=1);

namespace BitrixTelegram\Handlers;

use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Helpers\Logger;

/**
 * Обрабатывает входящие сообщения от Max Bot.
 * Токен бота берётся из параметра URL ?max_token=...
 */
class MaxBotIncomingHandler implements IncomingHandlerInterface
{
    public function __construct(
        private BitrixService     $bitrixService,
        private TokenRepository   $tokenRepository,
        private ProfileRepository $profileRepository,
        private ChatRepository    $chatRepository,
        private Logger            $logger
    ) {}

    public function handle(array $data): array
    {
        $maxToken = $_GET['max_token'] ?? null;

        if (empty($maxToken)) {
            $this->logger->warning('MaxBotHandler: max_token missing in URL');
            return ['status' => 'ok', 'message' => 'max_token missing'];
        }

        $profile = $this->profileRepository->findActiveByTokenAndType($maxToken, 'max');

        if (!$profile) {
            $this->logger->error('MaxBotHandler: profile not found', [
                'token_prefix' => substr($maxToken, 0, 10) . '...',
            ]);
            return ['status' => 'ok', 'message' => 'profile not found'];
        }

        $profileId = (int) $profile['id'];
        $message   = $data['message'] ?? null;

        if (!$message) {
            return ['status' => 'ok', 'action' => 'non_message_update'];
        }

        $chatId   = (string) (
            $message['sender']['user_id']
            ?? $message['chat_id']
            ?? $message['recipient']['chat_id']
            ?? ''
        );
        $userId   = (string) ($message['sender']['user_id'] ?? '');
        $userName = $message['sender']['name'] ?? $message['sender']['username'] ?? 'Unknown';
        $text     = $message['body']['text'] ?? '';

        if (empty($chatId)) {
            return ['status' => 'error', 'message' => 'chat_id not found'];
        }

        $this->logger->info('MaxBotHandler: incoming', [
            'profile_id' => $profileId,
            'chat_id'    => $chatId,
            'user'       => $userName,
            'text'       => mb_substr($text, 0, 50),
        ]);

        $domain = $this->profileRepository->getDomainByProfileId($profileId);

        if (!$domain) {
            $this->logger->error('MaxBotHandler: no domain for profile', ['profile_id' => $profileId]);
            return ['status' => 'error', 'message' => 'Domain not configured'];
        }

        $connectorId = $this->tokenRepository->getConnectorId($domain, 'max');

        if (!$connectorId) {
            $this->logger->error('MaxBotHandler: no connector', [
                'profile_id' => $profileId,
                'domain'     => $domain,
            ]);
            return ['status' => 'error', 'message' => 'Connector not found'];
        }

        $this->chatRepository->saveConnection('max', $chatId, $domain, $connectorId, $userName, $userId);
        $this->chatRepository->updateProfileId('max', $chatId, $profileId);

        $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);

        if (!$lineId) {
            return ['status' => 'error', 'message' => 'Line not configured'];
        }

        $files = $this->extractFiles($message);

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
                $this->logger->error('MaxBotHandler: failed to send to Bitrix24', ['result' => $result]);
            }
        }

        return ['status' => 'ok', 'action' => 'max_bot_message_sent'];
    }

    /**
     * Извлечь вложения из тела сообщения Max.
     */
    private function extractFiles(array $message): array
    {
        $files = [];
        $this->logger->info('ИНФОРМАЦИЯ О СООБЩЕНИИ ИЗ МАКС: ', $message);
        foreach (($message['body']['attachments'] ?? []) as $attachment) {
            $type    = $attachment['type'] ?? 'file';
            $payload = $attachment['payload'] ?? [];
            $fileUrl = $payload['url'] ?? '';

            if ($fileUrl) {
                $files[] = [
                    'url'  => $fileUrl,
                    'name' => $attachment['filename'] ?? 'file',
                    'type' => in_array($type, ['image', 'photo'], true) ? 'image' : 'file',
                ];
            }
        }

        return $files;
    }
}