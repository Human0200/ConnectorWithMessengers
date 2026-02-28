<?php

declare(strict_types=1);

namespace BitrixTelegram\Repositories;

use PDO;

class ChatRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        
        // Создаем таблицу если её нет
        $this->createTableIfNotExists();
    }

    /**
     * Создает таблицу для хранения связей чатов
     */
    private function createTableIfNotExists(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS messenger_chat_connections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                messenger_type VARCHAR(20) NOT NULL COMMENT 'telegram, max, whatsapp, viber, etc.',
                messenger_chat_id VARCHAR(100) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                connector_id VARCHAR(100) NOT NULL,
                user_name VARCHAR(255) DEFAULT NULL,
                user_id VARCHAR(100) DEFAULT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_messenger_chat (messenger_type, messenger_chat_id),
                KEY idx_domain (domain),
                KEY idx_connector (connector_id),
                KEY idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $this->pdo->exec($sql);
    }

    /**
     * Получить домен по chat_id мессенджера
     */
    public function getDomainByMessengerChat(string $messengerType, string $messengerChatId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT domain FROM messenger_chat_connections 
             WHERE messenger_type = ? AND messenger_chat_id = ? AND is_active = TRUE 
             ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute([$messengerType, $messengerChatId]);
        
        $domain = $stmt->fetchColumn();
        return $domain ?: null;
    }

    /**
     * Получить chat_id мессенджера по домену и типу
     */
    public function getMessengerChatByDomain(string $domain, string $messengerType): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT messenger_chat_id FROM messenger_chat_connections 
             WHERE domain = ? AND messenger_type = ? AND is_active = TRUE 
             ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute([$domain, $messengerType]);
        
        $chatId = $stmt->fetchColumn();
        return $chatId ?: null;
    }

    /**
     * Получить все активные чаты для домена
     */
    public function getMessengerChatsByDomain(string $domain): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT messenger_type, messenger_chat_id, user_name, user_id 
             FROM messenger_chat_connections 
             WHERE domain = ? AND is_active = TRUE 
             ORDER BY updated_at DESC'
        );
        $stmt->execute([$domain]);
        
        return $stmt->fetchAll();
    }

    /**
     * Сохранить связь чата мессенджера с доменом
     */
    public function saveConnection(
        string $messengerType,
        string $messengerChatId,
        string $domain,
        string $connectorId,
        ?string $userName = null,
        ?string $userId = null
    ): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO messenger_chat_connections 
             (messenger_type, messenger_chat_id, domain, connector_id, user_name, user_id, created_at) 
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE 
                connector_id = VALUES(connector_id),
                user_name = VALUES(user_name),
                user_id = VALUES(user_id),
                is_active = TRUE,
                updated_at = NOW()'
        );
        
        return $stmt->execute([
            $messengerType,
            $messengerChatId,
            $domain,
            $connectorId,
            $userName,
            $userId
        ]);
    }

    /**
     * Деактивировать связь чата
     */
    public function deactivateConnection(string $messengerType, string $messengerChatId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE messenger_chat_connections 
             SET is_active = FALSE, updated_at = NOW() 
             WHERE messenger_type = ? AND messenger_chat_id = ?'
        );
        
        return $stmt->execute([$messengerType, $messengerChatId]);
    }

    /**
     * Деактивировать все связи для домена
     */
    public function deactivateConnectionsByDomain(string $domain): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE messenger_chat_connections 
             SET is_active = FALSE, updated_at = NOW() 
             WHERE domain = ?'
        );
        
        return $stmt->execute([$domain]);
    }

    /**
     * Получить информацию о чате
     */
    public function getChatInfo(string $messengerType, string $messengerChatId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT domain, connector_id, user_name, user_id, is_active, created_at, updated_at 
             FROM messenger_chat_connections 
             WHERE messenger_type = ? AND messenger_chat_id = ? 
             ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute([$messengerType, $messengerChatId]);
        
        return $stmt->fetch() ?: null;
    }

    /**
     * Получить все активные соединения
     */
    public function getActiveConnections(): array
    {
        $stmt = $this->pdo->query(
            'SELECT messenger_type, messenger_chat_id, domain, connector_id, user_name, user_id, created_at, updated_at 
             FROM messenger_chat_connections 
             WHERE is_active = TRUE 
             ORDER BY updated_at DESC'
        );
        
        return $stmt->fetchAll();
    }

    /**
     * Проверить, существует ли активная связь
     */
    public function isConnectionActive(string $messengerType, string $messengerChatId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM messenger_chat_connections 
             WHERE messenger_type = ? AND messenger_chat_id = ? AND is_active = TRUE'
        );
        $stmt->execute([$messengerType, $messengerChatId]);
        
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Обновить информацию о пользователе
     */
    public function updateUserInfo(
        string $messengerType,
        string $messengerChatId,
        string $userName,
        string $userId
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE messenger_chat_connections 
             SET user_name = ?, user_id = ?, updated_at = NOW() 
             WHERE messenger_type = ? AND messenger_chat_id = ?'
        );
        
        return $stmt->execute([$userName, $userId, $messengerType, $messengerChatId]);
    }

    // Методы для обратной совместимости с Telegram

    /**
     * @deprecated Используйте getDomainByMessengerChat()
     */
    public function getDomainByTelegramChat(int $telegramChatId): ?string
    {
        return $this->getDomainByMessengerChat('telegram', (string) $telegramChatId);
    }

    /**
     * @deprecated Используйте getMessengerChatByDomain()
     */
    public function getTelegramChatByDomain(string $domain): ?int
    {
        $chatId = $this->getMessengerChatByDomain($domain, 'telegram');
        return $chatId ? (int) $chatId : null;
    }

    /**
     * @deprecated Используйте saveConnection()
     */
    public function saveTelegramConnection(
        string $domain,
        string $connectorId,
        int $telegramChatId
    ): bool {
        return $this->saveConnection('telegram', (string) $telegramChatId, $domain, $connectorId);
    }

    /**
     * @deprecated Используйте deactivateConnection()
     */
    public function deactivateTelegramConnection(int $telegramChatId): bool
    {
        return $this->deactivateConnection('telegram', (string) $telegramChatId);
    }
    /**
 * Сохранить profile_id для чата (с проверкой существования колонки)
 */
public function updateProfileId(string $messengerType, string $chatId, int $profileId): void
{
    try {
        $stmt = $this->pdo->prepare("
            UPDATE messenger_chat_connections
            SET profile_id = ?
            WHERE messenger_type = ? AND messenger_chat_id = ?
        ");
        $stmt->execute([$profileId, $messengerType, $chatId]);
    } catch (\PDOException $e) {
        // Если колонка не существует - создаем
        if ($e->errorInfo[1] == 1054) { // Unknown column
            $this->pdo->exec("ALTER TABLE messenger_chat_connections ADD COLUMN profile_id INT NULL");
            
            // Повторяем UPDATE
            $stmt = $this->pdo->prepare("
                UPDATE messenger_chat_connections
                SET profile_id = ?
                WHERE messenger_type = ? AND messenger_chat_id = ?
            ");
            $stmt->execute([$profileId, $messengerType, $chatId]);
        } else {
            throw $e;
        }
    }
}

/**
 * Получить токен бота по chat_id
 */
public function getBotTokenByChatId(string $chatId): ?string
{
    $stmt = $this->pdo->prepare("
        SELECT ump.token
        FROM messenger_chat_connections mcc
        JOIN user_messenger_profiles ump ON ump.id = mcc.profile_id
        WHERE mcc.messenger_type = 'telegram_bot'
          AND mcc.messenger_chat_id = ?
          AND mcc.is_active = 1
          AND ump.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$chatId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['token'] ?? null;
}

/**
 * Заполнить profile_id для max чата
 */
public function fillMaxProfileId(string $chatId, string $domain): void
{
    try {
        $stmt = $this->pdo->prepare("
            UPDATE messenger_chat_connections mcc
            JOIN profile_bitrix_connections pbc ON pbc.domain = mcc.domain
            JOIN user_messenger_profiles ump ON ump.id = pbc.profile_id
            SET mcc.profile_id = ump.id
            WHERE mcc.messenger_type = 'max'
              AND mcc.messenger_chat_id = ?
              AND mcc.domain = ?
              AND mcc.profile_id IS NULL
              AND ump.messenger_type = 'max'
              AND ump.is_active = 1
              AND pbc.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$chatId, $domain]);
    } catch (\PDOException $e) {
        // Логируем ошибку, но не прерываем выполнение
        error_log("fillMaxProfileId failed: " . $e->getMessage());
    }
}
}