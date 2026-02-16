<?php

declare(strict_types=1);

namespace BitrixTelegram\Controllers;

use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ChatRepository;
use BitrixTelegram\Helpers\BBCodeConverter;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Helpers\MessageDetector;
use BitrixTelegram\Messengers\MessengerFactory;
use BitrixTelegram\Messengers\MessengerInterface;

class WebhookController
{
    private BitrixService $bitrixService;
    private MessengerFactory $messengerFactory;
    private TokenRepository $tokenRepository;
    private ChatRepository $chatRepository;
    private BBCodeConverter $bbConverter;
    private Logger $logger;
    private MessageDetector $detector;

    public function __construct(
        BitrixService $bitrixService,
        MessengerFactory $messengerFactory,
        TokenRepository $tokenRepository,
        ChatRepository $chatRepository,
        BBCodeConverter $bbConverter,
        Logger $logger,
        MessageDetector $detector
    ) {
        $this->bitrixService = $bitrixService;
        $this->messengerFactory = $messengerFactory;
        $this->tokenRepository = $tokenRepository;
        $this->chatRepository = $chatRepository;
        $this->bbConverter = $bbConverter;
        $this->logger = $logger;
        $this->detector = $detector;
    }

    /**
     * Главный обработчик вебхуков
     */
    public function handleWebhook(array $data): array
    {
        $source = $this->detector->detectSource($data);

        $this->logger->info('Webhook received', [
            'source' => $source,
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

    private function handleBitrixToMessenger(array $data): array
    {
        $this->logger->info('Bitrix to Messenger', ['data' => $data]);

        if (empty($data['data']['CONNECTOR']) || empty($data['data']['MESSAGES'])) {
            return ['status' => 'error', 'message' => 'Invalid data'];
        }

        $connectorId = $data['data']['CONNECTOR'];
        $domain = $data['auth']['domain'] ?? '';

        foreach ($data['data']['MESSAGES'] as $message) {
            $bitrixChatId = $message['chat']['id'];

            // Определяем тип мессенджера по префиксу в chat_id
            $messengerType = $this->detectMessengerTypeFromChatId($bitrixChatId);

            // Получаем мессенджер
            $messenger = $this->messengerFactory->create($messengerType);

            // Очищаем префикс для получения реального chat_id
            $chatId = $this->cleanChatId($bitrixChatId, $messengerType);

            // Для Max нужно установить домен
            if ($messengerType === 'max' && method_exists($messenger, 'setDomain')) {
                $messenger->setDomain($domain);
            }

            // Проверяем, есть ли связь чата
            $chatInfo = $this->chatRepository->getChatInfo($messengerType, $chatId);
            if (!$chatInfo) {
                $this->logger->warning('Chat not found or not connected', [
                    'messengerType' => $messengerType,
                    'chatId' => $chatId,
                    'bitrixChatId' => $bitrixChatId
                ]);
                continue;
            }

            // ДЛЯ MAX: Нужно получить user_id
            if ($messengerType === 'max') {
                $maxUserId = $this->getMaxUserIdForChat($chatId, $domain);
                if (!$maxUserId) {
                    $this->logger->error('Max user_id not found for chat', [
                        'chatId' => $chatId,
                        'domain' => $domain
                    ]);
                    continue;
                }
                $recipientId = $maxUserId;
            } else {
                $recipientId = $chatId;
            }

            $text = $message['message']['text'] ?? '';

            // ОЧИСТКА ТЕКСТА: убираем HTML теги и форматирование
            if ($text) {
                $text = $this->cleanTextForMessenger($text);
            }

            $files = $message['message']['files'] ?? [];
            $result = ['ok' => false];

            // Отправляем файлы
            foreach ($files as $file) {
                $fileType = $file['type'] ?? '';
                $fileUrl = $file['downloadLink'] ?? $file['link'] ?? '';

                if ($fileType === 'image' && $fileUrl) {
                    $result = $messenger->sendPhoto($recipientId, $fileUrl, $text);
                    $text = '';
                } elseif ($fileUrl) {
                    $fileData = $data['data']['MESSAGES'][0]['message']['files'][0];
                    $this->logger->info('ОТПРАВЛЯЮ ФАЙЛ:', ['fileUrl' => $fileUrl]);
                    $result = $messenger->sendDocument($recipientId, $fileUrl, $text, $fileData);
                    $text = '';
                }
            }

            // Отправляем текст если не был отправлен с файлом
            if ($text) {
                $result = $messenger->sendMessage($recipientId, $text);
            }

            // Подтверждаем доставку в Bitrix24
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

    /**
     * Очистка текста для отправки в мессенджер
     * Убирает HTML теги и форматирование Bitrix24
     */
    private function cleanTextForMessenger(string $text): string
    {
        // 1. Убираем HTML теги
        $text = strip_tags($text);

        // 2. Убираем BBCode теги (если есть)
        $text = preg_replace('/\[(\w+)\](.*?)\[\/\1\]/s', '$2', $text);

        // 3. Заменяем HTML сущности
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 4. Убираем лишние пробелы и переносы строк
        $text = preg_replace('/\s+/', ' ', $text);

        // 5. Убираем специальные символы Bitrix24
        $text = str_replace(['[br]', '[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]', '[s]', '[/s]'], '', $text);

        // 6. Убираем форматирование пользователя (например: "Антон Русаков:")
        // $text = preg_replace('/^[^:]+:\s*/', '', $text);

        // 7. Убираем лишние двоеточия и тире
        $text = trim($text, " :-\t\n\r\0\x0B");

        return trim($text);
    }

    /**
     * Получить user_id Max для чата
     */
    private function getMaxUserIdForChat(string $chatId, string $domain): ?string
    {
        // Метод 1: Ищем в messenger_chat_connections
        $chatInfo = $this->chatRepository->getChatInfo('max', $chatId);
        if ($chatInfo && !empty($chatInfo['user_id'])) {
            return $chatInfo['user_id'];
        }

        return $chatId; // Временное решение
    }

    /**
     * Обработка сообщений из мессенджера в Bitrix24
     */
private function handleMessengerToBitrix(array $data, string $source): array
{
    $this->logger->info('Messenger to Bitrix', [
        'source' => $source,
        'data' => $data,
    ]);

    try {
        $messenger = $this->messengerFactory->create($source);
        
        $rawMessage = $data; // Просто передаем весь $data
        
        $this->logger->debug('Raw message for normalization', [
            'rawMessageKeys' => array_keys($rawMessage),
            'hasMessageKey' => isset($rawMessage['message']),
            'hasSessionId' => isset($rawMessage['session_id'])
        ]);
        
        $normalizedMessage = $messenger->normalizeIncomingMessage($rawMessage);
        
        $this->logger->debug('After normalization', [
            'normalizedKeys' => array_keys($normalizedMessage),
            'session_id' => $normalizedMessage['session_id'] ?? 'null'
        ]);

        $chatId = $normalizedMessage['chat_id'] ?? null;
        $userName = $normalizedMessage['user_name'] ?? 'Unknown';
        $userId = $normalizedMessage['user_id'] ?? null;
        $text = $normalizedMessage['text'] ?? '';

        $this->logger->info('Normalized message', [
            'chatId' => $chatId,
            'userName' => $userName,
            'userId' => $userId,
            'text' => $text
        ]);

        if (!$chatId) {
            $this->logger->error('Chat ID not found in normalized message', ['message' => $normalizedMessage]);
            return ['status' => 'error', 'message' => 'Chat ID not found'];
        }

        // 1. Пытаемся найти существующую связь
        $domain = $this->chatRepository->getDomainByMessengerChat($source, $chatId);
        $this->logger->info('Domain from messenger_chat_connections', ['domain' => $domain]);

        // 2. Обработка в зависимости от источника
        switch ($source) {
            case 'max':
                return $this->handleMaxMessenger($domain, $chatId, $messenger, $userName, $userId, $normalizedMessage);
            
            case 'telegram_user':
                return $this->handleTelegramMessenger($domain, $chatId, $messenger, $userName, $userId, $normalizedMessage);
            
            default:
                return $this->handleOtherMessenger($domain, $chatId, $messenger, $userName, $userId, $text, $source);
        }

    } catch (\Exception $e) {
        $this->logger->error('Error in handleMessengerToBitrix', [
            'error' => $e->getMessage(),
            'source' => $source,
            'trace' => $e->getTraceAsString()
        ]);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

    private function handleMaxMessenger($domain, $chatId, $messenger, $userName, $userId, $normalizedMessage): array
    {
        if (!$domain) {
            $this->logger->info('First message from Max, need to determine domain');

            $domainsWithTokens = $this->tokenRepository->findActiveDomainsWithMaxToken();
            if (empty($domainsWithTokens)) {
                $this->logger->error('No domains with Max token found');
                $messenger->sendMessage(
                    $chatId,
                    "⚠️ <b>Интеграция не настроена!</b>\n\nНастройте токен Max в Bitrix24 приложении."
                );
                return ['status' => 'error', 'message' => 'No Max token configured'];
            }

            $domains = array_values($domainsWithTokens);
            $domain = $domains[0];
            
            if (count($domains) > 1) {
                $this->logger->warning('Multiple domains with Max token, using first', [
                    'selected' => $domain,
                    'available' => $domains
                ]);
            }

            $connectorId = $this->tokenRepository->getConnectorId($domain, 'max');
            
            $this->chatRepository->saveConnection(
                'max',
                $chatId,
                $domain,
                $connectorId,
                $userName,
                $userId
            );

            $this->logger->info('Created new Max connection', [
                'chatId' => $chatId,
                'domain' => $domain,
                'connectorId' => $connectorId
            ]);

            $messenger->sendMessage(
                $chatId,
                "✅ <b>Соединение установлено!</b>\n\n🌐 <b>Домен:</b> $domain\nТеперь ваши сообщения будут отправляться в Bitrix24."
            );
        }

        return $this->processMessengerMessage($domain, 'max', $chatId, $messenger, $userName, $normalizedMessage);
    }

private function handleTelegramMessenger($domain, $chatId, $messenger, $userName, $userId, $normalizedMessage): array
{
    // Добавим проверку типа
    if (!is_array($normalizedMessage)) {
        $this->logger->error('Normalized message is not an array', [
            'type' => gettype($normalizedMessage),
            'value' => $normalizedMessage
        ]);
        return ['status' => 'error', 'message' => 'Invalid normalized message'];
    }
    
    // Для Telegram получаем session_id из сообщения
    $sessionId = $normalizedMessage['session_id'] ?? null;
    
    $this->logger->info('Normalized message for Telegram', [
        'sessionId' => $sessionId,
        'normalizedMessageKeys' => is_array($normalizedMessage) ? array_keys($normalizedMessage) : 'not an array'
    ]);

    if (!$domain) {
        if (!$sessionId) {
            $this->logger->error('Telegram session ID not found in normalized message', [
                'normalizedMessage' => $normalizedMessage
            ]);
            
            // Пробуем получить session_id из raw_data
            if (isset($normalizedMessage['raw_data']) && is_array($normalizedMessage['raw_data'])) {
                if (isset($normalizedMessage['raw_data']['session_id'])) {
                    $sessionId = $normalizedMessage['raw_data']['session_id'];
                    $this->logger->info('Found session_id in raw_data', ['sessionId' => $sessionId]);
                } elseif (isset($normalizedMessage['raw_data']['data']['session_id'])) {
                    $sessionId = $normalizedMessage['raw_data']['data']['session_id'];
                    $this->logger->info('Found session_id in raw_data[data]', ['sessionId' => $sessionId]);
                }
            }
            
            if (!$sessionId) {
                return ['status' => 'error', 'message' => 'Session ID not found'];
            }
        }
        
        $this->logger->info('Looking for session in repository', ['sessionId' => $sessionId]);
        
        // Ищем сессию по имени - метод возвращает строку или null
        $domain = $this->tokenRepository->getDomainBySession($sessionId);
        
        // Проверяем результат
        if (!$domain) {
            $this->logger->error('Telegram session not found in repository', [
                'session' => $sessionId
            ]);
            return ['status' => 'error', 'message' => 'Session not found'];
        }
        
        // Проверяем, что $domain - строка
        if (!is_string($domain)) {
            $this->logger->error('Invalid domain type from repository', []);
            return ['status' => 'error', 'message' => 'Invalid domain format'];
        }
        
        $connectorId = $this->tokenRepository->getConnectorId($domain, 'telegram');
        
        if (!$connectorId) {
            $this->logger->error('No connector ID for Telegram', ['domain' => $domain]);
            return ['status' => 'error', 'message' => 'Telegram connector not configured'];
        }
        
        // Сохраняем связь с дополнительными данными о сессии
        $this->chatRepository->saveConnection(
            'telegram_user',
            $chatId,
            $domain,
            $connectorId,
            $userName,
            (string)$userId,
        );
        
        $this->logger->info('Created new Telegram connection', [
            'session' => $sessionId,
            'chatId' => $chatId,
            'domain' => $domain,
            'connectorId' => $connectorId
        ]);
    } else {
        $this->logger->info('Using existing domain for Telegram', ['domain' => $domain]);
    }

    return $this->processMessengerMessage($domain, 'telegram_user', $chatId, $messenger, $userName, $normalizedMessage);
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
            $messenger->sendMessage(
                $chatId,
                "⚠️ <b>Открытая линия не настроена!</b>\n\nНастройте открытую линию в Bitrix24."
            );
            return ['status' => 'error', 'message' => 'Line not configured'];
        }

        $messagesToSend = $this->prepareMessagesForBitrix(
            $normalizedMessage,
            $messenger,
            $chatId,
            $userName
        );

        if (!empty($messagesToSend)) {
            $result = $this->bitrixService->sendMessages(
                $connectorId,
                $lineId,
                $messagesToSend,
                $domain
            );

            if (empty($result['result'])) {
                $messenger->sendMessage(
                    $chatId,
                    "❌ <b>Ошибка отправки сообщения в Bitrix24</b>"
                );
                $this->logger->error('Failed to send message to Bitrix24', ['result' => $result]);
            }
        }

        return ['status' => 'ok', 'action' => 'message_sent', 'source' => $source];
    }

    /**
     * Подготовка сообщений для отправки в Bitrix24
     */
    private function prepareMessagesForBitrix(
        array $normalized,
        MessengerInterface $messenger,
        string $chatId,
        string $userName
    ): array {
        $messages = [];
        $messengerType = $messenger->getType();

        $mainMessage = $this->createBitrixMessage(
            $chatId,
            $userName,
            null,
            $messengerType
        );
        $this->logger->info('Preparing message for Bitrix', ['normalized' => $normalized]);

        // Обработка файлов
        if (!empty($normalized['files'])) {
            $mainMessage['message']['files'] = [];

            foreach ($normalized['files'] as $file) {

                $fileUrl = $this->getFileUrl($file, $messenger);
                if ($fileUrl) {
                    // 1. Сначала из raw данных (оригинальное имя)
                    // 2. Потом из file['name']
                    // 3. Запасной вариант
                    $fileName = $file['filename']
                        ?? $normalized['raw']['body']['attachments'][0]['filename']
                        ?? $file['name']
                        ?? 'file';

                    $mainMessage['message']['files'][] = [
                        'url' => $fileUrl,
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

    /**
     * Создание сообщения для Bitrix24
     */
    private function createBitrixMessage(
        string $chatId,
        string $userName,
        ?string $text = null,
        string $messengerType = 'telegram'
    ): array {
        // Префиксы для разных мессенджеров
        $prefixes = [
            'telegram' => 'tg_',
            'max' => 'max_',
        ];

        $prefix = $prefixes[$messengerType] ?? $messengerType . '_';
        $prefixedChatId = $prefix . $chatId;

        $message = [
            'user' => [
                'id' => $chatId,
                'name' => $userName,
            ],
            'message' => [
                'date' => time(),
            ],
            'chat' => [
                'id' => $prefixedChatId,
            ],
        ];

        if ($text !== null) {
            $message['message']['text'] = $text;
        }

        return $message;
    }

    /**
     * Получить URL файла
     */
    private function getFileUrl(array $file, MessengerInterface $messenger): ?string
    {
        if (isset($file['url']) && !empty($file['url'])) {
            return $file['url'];
        }

        if (isset($file['id']) && !empty($file['id'])) {
            $fileInfo = $messenger->getFile($file['id']);
            if ($fileInfo && isset($fileInfo['file_path'])) {
                return $messenger->getFileUrl($fileInfo['file_path']);
            }
        }

        return null;
    }

    /**
     * Маппинг типов файлов для Bitrix24
     */
    private function mapFileType(string $type): string
    {
        $mapping = [
            'photo' => 'image',
            'image' => 'image',
            'document' => 'file',
            'voice' => 'audio',
            'video' => 'video',
            'audio' => 'audio',
            'file' => 'file',
        ];

        return $mapping[strtolower($type)] ?? 'file';
    }

    /**
     * Определяет тип мессенджера по chat_id
     */
    private function detectMessengerTypeFromChatId(string $chatId): string
    {
        if (str_starts_with($chatId, 'tg_') || str_starts_with($chatId, 'telegram_')) {
            return 'telegram';
        }
        if (str_starts_with($chatId, 'max_')) {
            return 'max';
        }

        return 'telegram'; // По умолчанию
    }

    /**
     * Очистка chat_id от префиксов
     */
    private function cleanChatId(string $chatId, string $messengerType): string
    {
        $prefixes = [
            'telegram' => ['tg_', 'telegram_'],
            'max' => ['max_'],
        ];

        $prefixesToRemove = $prefixes[$messengerType] ?? [];
        foreach ($prefixesToRemove as $prefix) {
            if (str_starts_with($chatId, $prefix)) {
                return substr($chatId, strlen($prefix));
            }
        }

        return $chatId;
    }

    /**
     * Отправка подтверждения доставки в Bitrix24
     */
    private function sendDeliveryConfirmation(
        string $connectorId,
        array $message,
        string $bitrixChatId,
        string $domain,
        bool $success
    ): void {
        try {
            $statusMessages = [[
                'im' => $message['im'] ?? '0',
                'message' => [
                    'id' => is_array($message['message']['id'] ?? null) ?
                        $message['message']['id'] :
                        [$message['message']['id'] ?? '0'],
                ],
                'chat' => ['id' => $bitrixChatId],
            ]];

            $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);

            if (!$lineId) {
                $this->logger->warning('Line ID not found for connector', ['connectorId' => $connectorId]);
                return;
            }

            if ($success) {
                $this->bitrixService->sendDeliveryStatus(
                    $connectorId,
                    $lineId,
                    $statusMessages,
                    $domain
                );
            } else {
                $this->bitrixService->sendErrorStatus(
                    $connectorId,
                    $lineId,
                    $statusMessages,
                    $domain
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Error sending delivery confirmation', [
                'error' => $e->getMessage(),
                'connectorId' => $connectorId,
            ]);
        }
    }

    /**
     * Обработка команд бота
     */
    private function processBotCommand(
        string $messengerType,
        string $chatId,
        string $text,
        MessengerInterface $messenger,
        string $userName = '',
        string $userId = ''
    ): void {
        $text = trim($text);

        if (!str_starts_with($text, '/')) {
            return;
        }

        $command = strtolower(trim($text, '/'));

        switch ($command) {
            case 'start':
                $message = "👋 <b>Добро пожаловать, $userName!</b>\n\n" .
                    "Для привязки аккаунта отправьте ваш домен Bitrix24.\n" .
                    "Пример: <code>mydomain.bitrix24.ru</code>";
                break;

            case 'help':
                $message = "🆘 <b>Доступные команды:</b>\n\n" .
                    "/start - Начать работу\n" .
                    "/help - Показать справку\n" .
                    "/status - Проверить статус привязки\n" .
                    "Отправьте домен Bitrix24 для привязки (например: mydomain.bitrix24.ru)";
                break;

            case 'status':
                $chatInfo = $this->chatRepository->getChatInfo($messengerType, $chatId);
                if ($chatInfo && $chatInfo['is_active']) {
                    $domain = $chatInfo['domain'];
                    $connectorId = $this->tokenRepository->getConnectorId($domain, $messengerType);
                    $message = "✅ <b>Аккаунт привязан</b>\n\n" .
                        "🌐 <b>Домен:</b> $domain\n" .
                        "🤖 <b>Мессенджер:</b> " . ucfirst($messengerType) . "\n" .
                        "🆔 <b>Connector ID:</b> " . ($connectorId ?? 'не найден') . "\n" .
                        "✅ Готов к работе!";
                } else {
                    $message = "❌ <b>Аккаунт не привязан</b>\n\n" .
                        "Отправьте ваш домен Bitrix24 для привязки.\n" .
                        "Пример: <code>mydomain.bitrix24.ru</code>";
                }
                break;

            default:
                if ($this->isValidDomain($text)) {
                    $domain = $text;
                    $connectorId = $this->tokenRepository->getConnectorId($domain, $messengerType);

                    if ($connectorId) {
                        $this->chatRepository->saveConnection(
                            $messengerType,
                            $chatId,
                            $domain,
                            $connectorId,
                            $userName,
                            $userId
                        );
                        $message = "✅ <b>Домен успешно привязан!</b>\n\n" .
                            "🌐 <b>Домен:</b> $domain\n" .
                            "🤖 <b>Мессенджер:</b> " . ucfirst($messengerType) . "\n" .
                            "🆔 <b>Connector ID:</b> $connectorId\n" .
                            "👤 <b>Пользователь:</b> $userName\n" .
                            "Используйте /status для проверки статуса.";
                    } else {
                        $message = "❌ <b>Домен не найден!</b>\n\n" .
                            "Сначала установите приложение в Bitrix24 на домене: $domain";
                    }
                } else {
                    $message = "❌ <b>Неизвестная команда</b>\n\n" .
                        "Используйте /help для просмотра доступных команд.";
                }
                break;
        }

        $messenger->sendMessage($chatId, $message);
    }

    /**
     * Валидация домена Bitrix24
     */
    private function isValidDomain(string $domain): bool
    {
        $domain = trim($domain);
        $domain = preg_replace('/^https?:\/\//', '', $domain);

        return preg_match('/^[a-zA-Z0-9.-]+\.bitrix24\.(ru|com|by|kz|ua|su)$/', $domain) === 1;
    }
}
