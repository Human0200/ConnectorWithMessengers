<?php

declare(strict_types=1);

namespace BitrixTelegram\Messengers;

use BitrixTelegram\Services\MaxService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

class MaxMessenger implements MessengerInterface
{
    private ?string $currentDomain = null;

    /**
     * Токен, установленный напрямую (из user_messenger_profiles.token).
     * Приоритет над поиском токена по домену в bitrix_integration_tokens.
     */
    private ?string $explicitToken = null;

    public function __construct(
        private TokenRepository $tokenRepository,
        private Logger $logger,
        private MaxService $maxService
    ) {}

    public function setDomain(string $domain): void
    {
        $this->currentDomain = $domain;
        $this->logger->debug('Domain set for MaxMessenger', ['domain' => $domain]);
    }

    public function getDomain(): ?string
    {
        return $this->currentDomain;
    }

    /**
     * Установить токен напрямую из профиля.
     * Когда установлен — MaxService использует его, не ища в БД по домену.
     */
    public function setToken(string $token): void
    {
        $this->explicitToken = $token;
        $this->logger->debug('Explicit token set for MaxMessenger');
    }

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

        $result = $this->explicitToken
            ? $this->maxService->sendMessageWithToken($chatId, $text, $this->explicitToken)
            : $this->maxService->sendMessage($chatId, $text, $this->currentDomain);

        return [
            'ok'     => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error'  => $result['error'] ?? null,
        ];
    }

    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        $result = $this->explicitToken
            ? $this->maxService->sendImageWithToken($chatId, $photoUrl, $caption, $this->explicitToken)
            : $this->maxService->sendImage($chatId, $photoUrl, $caption, $this->currentDomain);

        return [
            'ok'     => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error'  => $result['error'] ?? null,
        ];
    }

    public function sendDocument(string $chatId, string $documentUrl, ?string $caption = null, ?array $fileData = null): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        $originalName = $fileData['name'] ?? null;

        $result = $this->explicitToken
            ? $this->maxService->sendFileWithToken($chatId, $documentUrl, $caption, $this->explicitToken, $originalName)
            : $this->maxService->sendFile($chatId, $documentUrl, $caption, $this->currentDomain, $originalName);

        return [
            'ok'     => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error'  => $result['error'] ?? null,
        ];
    }

    public function sendVoice(string $chatId, string $voiceUrl): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        $result = $this->explicitToken
            ? $this->maxService->sendAudioWithToken($chatId, $voiceUrl, $this->explicitToken)
            : $this->maxService->sendAudio($chatId, $voiceUrl, $this->currentDomain);

        return [
            'ok'     => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error'  => $result['error'] ?? null,
        ];
    }

    public function sendVideo(string $chatId, string $videoUrl, ?string $caption = null): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }

        $result = $this->explicitToken
            ? $this->maxService->sendVideoWithToken($chatId, $videoUrl, $caption, $this->explicitToken)
            : $this->maxService->sendVideo($chatId, $videoUrl, $caption, $this->currentDomain);

        return [
            'ok'     => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error'  => $result['error'] ?? null,
        ];
    }

    public function getFile(string $fileId): ?array
    {
        if (!$this->checkDomain()) return null;
        return $this->maxService->getFile($fileId, $this->currentDomain);
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
            'ok'     => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error'  => $result['error'] ?? null,
        ];
    }

    public function getInfo(): array
    {
        if (!$this->checkDomain()) {
            return ['ok' => false, 'error' => 'Domain not set'];
        }
        $result = $this->maxService->getWebhookInfo($this->currentDomain);
        if (!$result['success']) {
            $result = $this->maxService->getUserInfo('me', $this->currentDomain);
        }
        return [
            'ok'     => $result['success'] ?? false,
            'result' => $result['data'] ?? null,
            'error'  => $result['error'] ?? null,
        ];
    }

    public function normalizeIncomingMessage(array $message): array
    {
        $rawMessage = $message['message'] ?? $message;

        $normalized = [
            'message_id'   => null,
            'chat_id'      => null,
            'user_id'      => null,
            'user_name'    => '',
            'text'         => null,
            'timestamp'    => null,
            'files'        => [],
            'message_type' => 'text',
            'reply_to'     => null,
            'raw'          => $rawMessage,
        ];

        try {
            if (isset($rawMessage['recipient']['chat_id'])) {
                $normalized['chat_id'] = (string) $rawMessage['recipient']['chat_id'];
            }

            if (isset($rawMessage['sender']['user_id'])) {
                $normalized['user_id'] = (string) $rawMessage['sender']['user_id'];
            }

            $userNameParts = array_filter([
                $rawMessage['sender']['first_name'] ?? '',
                $rawMessage['sender']['last_name'] ?? '',
            ]);
            $normalized['user_name'] = !empty($userNameParts)
                ? implode(' ', $userNameParts)
                : ($rawMessage['sender']['name'] ?? 'User');

            $normalized['text']       = $rawMessage['body']['text'] ?? null;
            $normalized['message_id'] = $rawMessage['body']['mid'] ?? null;
            $normalized['timestamp']  = (int) ($rawMessage['timestamp'] ?? $message['timestamp'] ?? time());

            foreach ($rawMessage['body']['attachments'] ?? [] as $attachment) {
                $fileInfo = $this->normalizeAttachment($attachment);
                if ($fileInfo) {
                    $normalized['files'][] = $fileInfo;
                }
            }

            $normalized['message_type'] = $this->detectMessageType($normalized);
        } catch (\Exception $e) {
            $this->logger->error('Error normalizing Max message: ' . $e->getMessage());
        }

        return $normalized;
    }

    private function detectMessageType(array $normalized): string
    {
        if (!empty($normalized['files'])) {
            return $normalized['files'][0]['type'] ?? 'file';
        }
        return !empty($normalized['text']) ? 'text' : 'unknown';
    }

    private function normalizeAttachment(array $attachment): ?array
    {
        if (!isset($attachment['type'])) return null;

        $payload  = $attachment['payload'] ?? [];
        $fileInfo = [
            'id'        => null,
            'type'      => $attachment['type'],
            'url'       => null,
            'mime_type' => null,
            'size'      => null,
            'name'      => null,
        ];

        switch ($attachment['type']) {
            case 'image':
                $fileInfo['url']       = $payload['url'] ?? null;
                $fileInfo['id']        = isset($payload['photo_id']) ? (string) $payload['photo_id'] : null;
                $ext                   = $this->getImageExtensionFromUrl($fileInfo['url'] ?? '');
                $fileInfo['name']      = 'image_' . time() . '.' . $ext;
                $fileInfo['file_name'] = $fileInfo['name'];
                break;
            case 'video':
                $fileInfo['url']       = $payload['url'] ?? null;
                $fileInfo['name']      = 'video_' . time() . '.mp4';
                $fileInfo['file_name'] = $fileInfo['name'];
                break;
            case 'audio':
                $fileInfo['url']       = $payload['url'] ?? null;
                $fileInfo['name']      = 'audio_' . time() . '.mp3';
                $fileInfo['file_name'] = $fileInfo['name'];
                break;
            case 'file':
                $fileInfo['url']       = $payload['url'] ?? null;
                $fileInfo['name']      = $payload['filename'] ?? ('file_' . time());
                $fileInfo['file_name'] = $fileInfo['name'];
                break;
        }

        return !empty($fileInfo['url']) ? $fileInfo : null;
    }

    private function getImageExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return $ext;
        }
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        return isset($query['ext']) ? strtolower($query['ext']) : 'jpg';
    }

    public function denormalizeOutgoingMessage(array $message): array
    {
        $result = ['user_id' => $message['chat_id'] ?? ''];
        if (!empty($message['text'])) {
            $result['text'] = $message['text'];
        }
        if (!empty($message['files'])) {
            $file = $message['files'][0];
            $result['attachments'] = [[
                'type'    => $this->mapToMaxFileType($file['type'] ?? 'document'),
                'payload' => ['url' => $file['url'] ?? ''],
            ]];
        }
        return $result;
    }

    private function mapToMaxFileType(string $type): string
    {
        return match (strtolower($type)) {
            'photo', 'image' => 'image',
            'voice', 'audio' => 'audio',
            'video'          => 'video',
            default          => 'file',
        };
    }

    public function getType(): string
    {
        return 'max';
    }

    public function checkConnection(): bool
    {
        return $this->currentDomain
            ? $this->maxService->checkConnection($this->currentDomain)
            : false;
    }
}