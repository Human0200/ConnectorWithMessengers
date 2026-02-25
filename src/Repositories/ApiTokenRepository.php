<?php

declare(strict_types=1);

namespace BitrixTelegram\Repositories;

use PDO;

class ApiTokenRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->createTableIfNotExists();
    }

    private function createTableIfNotExists(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS api_tokens (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                token         VARCHAR(64) NOT NULL UNIQUE,
                name          VARCHAR(255) NOT NULL COMMENT 'Human-readable label',
                domain        VARCHAR(255) DEFAULT NULL COMMENT 'Linked Bitrix24 domain',
                scopes        JSON DEFAULT NULL COMMENT 'Allowed scopes: [\"read\",\"write\",\"admin\"]',
                is_active     BOOLEAN DEFAULT TRUE,
                last_used_at  TIMESTAMP NULL DEFAULT NULL,
                expires_at    TIMESTAMP NULL DEFAULT NULL COMMENT 'NULL = never expires',
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_token (token),
                KEY idx_domain (domain),
                KEY idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Сгенерировать и сохранить новый API-токен
     */
    public function create(string $name, ?string $domain = null, array $scopes = ['read', 'write'], ?\DateTime $expiresAt = null): array
    {
        $token = $this->generateToken();

        $stmt = $this->pdo->prepare("
            INSERT INTO api_tokens (token, name, domain, scopes, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $token,
            $name,
            $domain,
            json_encode($scopes),
            $expiresAt?->format('Y-m-d H:i:s'),
        ]);

        return $this->findByToken($token);
    }

    /**
     * Найти токен и проверить его валидность
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM api_tokens WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['scopes'] = json_decode($row['scopes'] ?? '[]', true);
        return $row;
    }

    /**
     * Валидация токена: активен, не истёк
     */
    public function validate(string $token): ?array
    {
        $record = $this->findByToken($token);

        if (!$record) {
            return null;
        }

        if (!$record['is_active']) {
            return null;
        }

        if ($record['expires_at'] !== null && strtotime($record['expires_at']) < time()) {
            return null;
        }

        // Обновляем last_used_at
        $this->touchLastUsed($token);

        return $record;
    }

    /**
     * Все токены (для UI)
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, name, domain, scopes, is_active, last_used_at, expires_at, created_at,
                   CONCAT(LEFT(token, 8), '...', RIGHT(token, 4)) AS token_preview
            FROM api_tokens
            ORDER BY created_at DESC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['scopes'] = json_decode($row['scopes'] ?? '[]', true);
        }
        return $rows;
    }

    /**
     * Токены по домену
     */
    public function getByDomain(string $domain): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM api_tokens WHERE domain = ? AND is_active = TRUE ORDER BY created_at DESC
        ");
        $stmt->execute([$domain]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['scopes'] = json_decode($row['scopes'] ?? '[]', true);
        }
        return $rows;
    }

    /**
     * Деактивировать токен
     */
    public function revoke(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE api_tokens SET is_active = FALSE WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Удалить токен
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM api_tokens WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Привязать токен к домену
     */
    public function linkDomain(int $id, string $domain): bool
    {
        $stmt = $this->pdo->prepare("UPDATE api_tokens SET domain = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$domain, $id]);
    }

    /**
     * Обновить last_used_at
     */
    private function touchLastUsed(string $token): void
    {
        $stmt = $this->pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE token = ?");
        $stmt->execute([$token]);
    }

    /**
     * Генерация крипто-стойкого токена
     * Формат: btg_<32 байта hex> → 67 символов
     */
    private function generateToken(): string
    {
        return 'btg_' . bin2hex(random_bytes(32));
    }

    /**
     * Статистика
     */
    public function getStats(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(is_active = 1) AS active,
                SUM(is_active = 0) AS revoked,
                SUM(domain IS NOT NULL AND is_active = 1) AS linked,
                SUM(last_used_at IS NOT NULL) AS used
            FROM api_tokens
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}