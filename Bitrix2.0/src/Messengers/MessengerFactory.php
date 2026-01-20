<?php

declare(strict_types=1);

namespace BitrixTelegram\Messengers;

use BitrixTelegram\Services\MaxService;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Helpers\MessageDetector;
use BitrixTelegram\Repositories\TokenRepository;

class MessengerFactory
{
    private array $config;
    private Logger $logger;
    private TokenRepository $tokenRepository;
    private MessageDetector $detector;
    private ?MaxService $maxService = null;

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

    /**
     * Создает мессенджер по типу
     */
    public function create(string $type): MessengerInterface
    {
        switch ($type) {
            case MessageDetector::SOURCE_TELEGRAM:
                // Передаем null как токен, он будет получен динамически из БД
                return new TelegramMessenger(
                    null,
                    $this->logger,
                    $this->tokenRepository
                );

            case MessageDetector::SOURCE_MAX:
                // Создаем MaxService если его еще нет
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

    private function createTelegram(): MessengerInterface
    {
        if (empty($this->config['telegram']['bot_token'])) {
            // Если нет токена в конфиге, создаем мессенджер без токена
            // Токен будет установлен позже через setBotToken()
            return new TelegramMessenger(null, $this->logger, $this->tokenRepository);
        }

        return new TelegramMessenger(
            $this->config['telegram']['bot_token'],
            $this->logger,
            $this->tokenRepository
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