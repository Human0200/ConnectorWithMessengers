<?php

declare(strict_types=1);

namespace BitrixTelegram\Messengers;

use BitrixTelegram\Services\MaxService;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Helpers\MessageDetector;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Services\MadelineProtoService;

class MessengerFactory
{
    private array $config;
    private Logger $logger;
    private TokenRepository $tokenRepository;
    private MessageDetector $detector;
    private ?MaxService $maxService = null;
    private ?MadelineProtoService $madelineProtoService = null;

    public function __construct(
        array $config,
        Logger $logger,
        TokenRepository $tokenRepository,
        MessageDetector $detector
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->tokenRepository = $tokenRepository;
        $this->detector = $detector;
    }

    /**
     * Устанавливает MaxService (опционально, для инъекции зависимостей).
     */
    public function setMaxService(MaxService $maxService): void
    {
        $this->maxService = $maxService;
    }

    /**
     * Создает мессенджер на основе входящих данных
     */
    public function createFromRequest(array $data): MessengerInterface
    {
        $source = $this->detector->detectSource($data);

        $this->logger->debug('Creating messenger', [
            'detected_source' => $source,
        ]);

        return $this->create($source);
    }

    private function createMadelineProtoService(): MadelineProtoService
    {
        return new MadelineProtoService(
            $this->tokenRepository,
            $this->logger,
            $this->config['telegram']['api_id'],
            $this->config['telegram']['api_hash'],
            $this->config['sessions']['path'] ?? null
        );
    }

    /**
     * Создает мессенджер по типу
     */

    public function create(string $type): MessengerInterface
    {
        switch ($type) {
            case MessageDetector::SOURCE_TELEGRAM:
                return new TelegramMessenger(
                    null,
                    $this->logger,
                    $this->tokenRepository
                );

            case MessageDetector::SOURCE_TELEGRAM_USER:
                if (!$this->madelineProtoService) {
                    $this->madelineProtoService = $this->createMadelineProtoService();
                }

                return  new MadelineProtoMessenger(
                    $this->logger,
                    $this->tokenRepository,
                    $this->madelineProtoService
                );

            case MessageDetector::SOURCE_MAX:
                if (!$this->maxService) {
                    $this->maxService = $this->createMaxService();
                }

                return new MaxMessenger(
                    $this->tokenRepository,
                    $this->logger,
                    $this->maxService
                );

            default:
                throw new \InvalidArgumentException("Unsupported messenger type: $type");
        }
    }

    /**
     * Создает экземпляр MaxService
     */
    private function createMaxService(): MaxService
    {
        $apiUrl = $this->config['max']['api_url'] ?? 'https://platform-api.max.ru';

        return new MaxService(
            $this->tokenRepository,
            $this->logger,
            $apiUrl
        );
    }

    /**
     * Создает мессенджер по chat_id
     */
    public function createByChatId(string $chatId): MessengerInterface
    {
        // Определяем тип по префиксу chat_id
        if ($this->detector->isMaxChatId($chatId)) {
            $messenger = $this->create(MessageDetector::SOURCE_MAX);

            // Устанавливаем домен для MaxMessenger
            if ($messenger instanceof MaxMessenger) {
                $cleanChatId = str_replace('max_', '', $chatId);
            }

            return $messenger;
        }

        // По умолчанию Telegram (числовой ID)
        $messenger = $this->create(MessageDetector::SOURCE_TELEGRAM);

        // Для Telegram получаем токен из БД по chat_id
        $cleanChatId = str_replace('max_', '', $chatId);
        $token = $this->tokenRepository->getTelegramTokenByChatId((int) $cleanChatId);

        if ($token && $messenger instanceof TelegramMessenger) {
            $messenger->setBotToken($token);
        }

        return $messenger;
    }

    /**
     * Получить все доступные типы мессенджеров
     */
    public function getSupportedTypes(): array
    {
        return [
            MessageDetector::SOURCE_TELEGRAM,
            MessageDetector::SOURCE_MAX,
        ];
    }
}
