<?php

// declare(strict_types=1);

namespace BitrixTelegram\Repositories;

use PDO;

class TokenRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByDomain(string $domain): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM bitrix_integration_tokens WHERE domain = ? LIMIT 1'
        );
        $stmt->execute([$domain]);

        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getConnectorId(string $domain, string $typeofmessenger): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT connector_id FROM bitrix_integration_tokens 
             WHERE domain = ? AND connector_id IS NOT NULL AND connector_id != ""'
        );
        $stmt->execute([$domain]);

        $connectorId = $stmt->fetchColumn();

        if ($connectorId) {
            $this->updateLastUpdated($domain);
            return $connectorId;
        }

        return $this->createConnectorId($domain, $typeofmessenger);
    }

    /**
     * Найти домен по токену Max
     */
    public function findDomainByMaxToken(string $maxToken): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT domain FROM bitrix_integration_tokens 
         WHERE api_token_max = ? AND is_active = TRUE 
         ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute([$maxToken]);

        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Найти все активные домены с токенами Max
     */
    public function findActiveDomainsWithMaxToken(): array
    {
        $stmt = $this->pdo->query(
            'SELECT domain, api_token_max FROM bitrix_integration_tokens 
         WHERE api_token_max IS NOT NULL AND api_token_max != "" 
         AND is_active = TRUE'
        );

        $result = [];
        while ($row = $stmt->fetch()) {
            if (!empty($row['api_token_max'])) {
                $result[$row['api_token_max']] = $row['domain'];
            }
        }

        return $result;
    }

    public function createConnectorId(string $domain, string $typeofmessenger): string
    {
        if ($typeofmessenger === 'telegram') {
            $connectorId = 'telegram_' . bin2hex(random_bytes(8));
        } else {
            $connectorId = 'max_' . bin2hex(random_bytes(8));
        }


        $stmt = $this->pdo->prepare(
            'SELECT id FROM bitrix_integration_tokens WHERE domain = ?'
        );
        $stmt->execute([$domain]);
        $existingRecord = $stmt->fetchColumn();

        if ($existingRecord) {
            $stmt = $this->pdo->prepare(
                'UPDATE bitrix_integration_tokens 
                 SET connector_id = ?, last_updated = NOW() 
                 WHERE domain = ?'
            );
            $stmt->execute([$connectorId, $domain]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO bitrix_integration_tokens 
                 (domain, connector_id, last_updated) 
                 VALUES (?, ?, NOW())'
            );
            $stmt->execute([$domain, $connectorId]);
        }

        return $connectorId;
    }

    public function updateAccessToken(
        string $domain,
        string $accessToken,
        int $expiresAt,
        ?string $refreshToken = null
    ): bool {
        $query = 'UPDATE bitrix_integration_tokens 
                  SET access_token = ?, token_expires = ?, last_updated = NOW()';
        $params = [$accessToken, $expiresAt];

        if ($refreshToken !== null) {
            $query .= ', refresh_token = ?';
            $params[] = $refreshToken;
        }

        $query .= ' WHERE domain = ?';
        $params[] = $domain;

        $stmt = $this->pdo->prepare($query);
        return $stmt->execute($params);
    }

    public function saveMaxToken(string $domain, string $apiTokenMax): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM bitrix_integration_tokens WHERE domain = ?'
        );
        $stmt->execute([$domain]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $this->pdo->prepare(
                'UPDATE bitrix_integration_tokens 
                 SET api_token_max = ?, last_updated = NOW() 
                 WHERE domain = ?'
            );
            return $stmt->execute([$apiTokenMax, $domain]);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO bitrix_integration_tokens 
             (domain, api_token_max, date_created, last_updated) 
             VALUES (?, ?, NOW(), NOW())'
        );
        return $stmt->execute([$domain, $apiTokenMax]);
    }

    public function saveTelegramToken(string $domain, string $telegramBotToken): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM bitrix_integration_tokens WHERE domain = ?'
        );
        $stmt->execute([$domain]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $this->pdo->prepare(
                'UPDATE bitrix_integration_tokens 
                 SET telegram_bot_token = ?, last_updated = NOW() 
                 WHERE domain = ?'
            );
            return $stmt->execute([$telegramBotToken, $domain]);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO bitrix_integration_tokens 
             (domain, telegram_bot_token, date_created, last_updated) 
             VALUES (?, ?, NOW(), NOW())'
        );
        return $stmt->execute([$domain, $telegramBotToken]);
    }

    public function getTelegramToken(string $domain): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT telegram_bot_token FROM bitrix_integration_tokens WHERE domain = ?'
        );
        $stmt->execute([$domain]);

        $token = $stmt->fetchColumn();
        return $token ?: null;
    }

    public function getTelegramTokenByChatId(int $chatId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT bit.telegram_bot_token 
             FROM bitrix_integration_tokens bit
             JOIN telegram_chat_connections tcc ON bit.domain = tcc.domain
             WHERE tcc.telegram_chat_id = ? AND tcc.is_active = TRUE
             LIMIT 1'
        );
        $stmt->execute([$chatId]);

        $token = $stmt->fetchColumn();
        return $token ?: null;
    }

    public function getLineByConnectorId(string $connectorId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_openline FROM bitrix_integration_tokens WHERE connector_id = ?'
        );
        $stmt->execute([$connectorId]);

        $line = $stmt->fetchColumn();
        return $line ? (int) $line : null;
    }

    public function updateLine(string $connectorId, int $lineId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bitrix_integration_tokens SET id_openline = ? WHERE connector_id = ?'
        );
        return $stmt->execute([$lineId, $connectorId]);
    }

    private function updateLastUpdated(string $domain): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bitrix_integration_tokens SET last_updated = NOW() WHERE domain = ?'
        );
        $stmt->execute([$domain]);
    }

    public function saveInstallData(array $data): bool
    {
        try {
            // 1. Создаем hookToken
            $hookToken = rtrim(
                strtr(base64_encode(json_encode($data)), '+/', '-_'),
                '='
            );

            // 2. Определяем expires
            $expires = $data['expires'] ?? $data['AUTH_EXPIRES'] ?? time() + 3600;
            if (is_string($expires) && !is_numeric($expires)) {
                $expires = strtotime($expires);
            }
            $expires = (int)$expires;

            // 3. Определяем client_endpoint
            $clientEndpoint = $data['client_endpoint'] ??
                ($data['auth']['client_endpoint'] ??
                    ('https://' . ($data['domain'] ?? '') . '/rest/'));

            // 4. Подготавливаем SQL
            $sql = 'INSERT INTO bitrix_integration_tokens 
                (domain, member_id, refresh_token, access_token, client_id, 
                 client_secret, hook_token, token_expires, client_endpoint)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                   member_id = VALUES(member_id),
                   refresh_token = VALUES(refresh_token),
                   access_token = VALUES(access_token),
                   client_id = VALUES(client_id),
                   client_secret = VALUES(client_secret),
                   hook_token = VALUES(hook_token),
                   token_expires = VALUES(token_expires),
                   client_endpoint = VALUES(client_endpoint),
                   last_updated = CURRENT_TIMESTAMP';

            // 5. Подготавливаем параметры
            $params = [
                $data['domain'] ?? '',
                $data['member_id'] ?? '',
                $data['refresh_token'] ?? '',
                $data['access_token'] ?? '',
                $data['client_id'] ?? '',
                $data['client_secret'] ?? '',
                $hookToken,
                $expires,
                $clientEndpoint,
            ];

            // 6. Выполняем запрос
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (\Exception $e) {
            throw $e;
        }
    }

  /**
     * Сохранить сессию MadelineProto
     */
    public function saveMadelineProtoSession(
        string $domain,
        string $sessionId,
        string $sessionFile,
        ?string $sessionName = null, // ← Делаем nullable
        ?int $accountId = null,
        ?string $accountUsername = null,
        ?string $accountFirstName = null,
        string $status = 'authorized'
    ): bool {
        // Проверяем существование
        $stmt = $this->pdo->prepare(
            'SELECT id FROM madelineproto_sessions 
             WHERE domain = ? AND session_id = ?'
        );
        $stmt->execute([$domain, $sessionId]);
        
        $sessionName = $sessionName ?: 'Сессия ' . date('Y-m-d H:i:s'); // ← Значение по умолчанию
        
        if ($stmt->fetch()) {
            // Обновляем существующую
            $stmt = $this->pdo->prepare(
                'UPDATE madelineproto_sessions 
                 SET session_file = ?,
                     session_name = COALESCE(?, session_name), -- ← Сохраняем старое если null
                     account_id = ?,
                     account_username = ?,
                     account_first_name = ?,
                     status = ?,
                     updated_at = NOW()
                 WHERE domain = ? AND session_id = ?'
            );
            return $stmt->execute([
                $sessionFile,
                $sessionName,
                $accountId,
                $accountUsername,
                $accountFirstName,
                $status,
                $domain,
                $sessionId
            ]);
        } else {
            // Создаем новую
            $stmt = $this->pdo->prepare(
                'INSERT INTO madelineproto_sessions 
                 (domain, session_id, session_name, session_file, 
                  account_id, account_username, account_first_name, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            return $stmt->execute([
                $domain,
                $sessionId,
                $sessionName,
                $sessionFile,
                $accountId,
                $accountUsername,
                $accountFirstName,
                $status
            ]);
        }
    }

    /**
     * Обновить только имя сессии
     */
    public function updateSessionName(string $domain, string $sessionId, string $sessionName): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE madelineproto_sessions 
             SET session_name = ?, updated_at = NOW()
             WHERE domain = ? AND session_id = ?'
        );
        return $stmt->execute([$sessionName, $domain, $sessionId]);
    }

    /**
     * Обновить только статус сессии
     */
    public function updateSessionStatus(string $domain, string $sessionId, string $status): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE madelineproto_sessions 
             SET status = ?, updated_at = NOW()
             WHERE domain = ? AND session_id = ?'
        );
        return $stmt->execute([$status, $domain, $sessionId]);
    }

    /**
     * Получить все сессии для домена
     */
    public function getMadelineProtoSessions(string $domain): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM madelineproto_sessions 
             WHERE domain = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$domain]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Получить конкретную сессию
     */
    public function getMadelineProtoSession(string $domain, string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM madelineproto_sessions 
             WHERE domain = ? AND session_id = ?'
        );
        $stmt->execute([$domain, $sessionId]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Получить Домен по сессии
     */
public function getDomainBySession(string $sessionId): ?string
{
    // Проверяем, что PDO инициализирован
    if (!$this->pdo) {
        throw new \RuntimeException('PDO connection is not initialized');
    }

    try {
        $stmt = $this->pdo->prepare(
            'SELECT domain FROM madelineproto_sessions 
             WHERE session_id = ?'
        );
        
        if (!$stmt) {
            error_log('Failed to prepare statement for session: ' . $sessionId);
            return null;
        }
        
        $stmt->execute([$sessionId]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        
        return $result['domain'] ?? null;
    } catch (\PDOException $e) {
        error_log('PDO error in getDomainBySession: ' . $e->getMessage());
        return null;
    }
}

    /**
     * Удалить сессию
     */
    public function deleteMadelineProtoSession(string $domain, string $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM madelineproto_sessions 
             WHERE domain = ? AND session_id = ?'
        );
        return $stmt->execute([$domain, $sessionId]);
    }

    /**
     * Получить активные (авторизованные) сессии
     */
    public function getActiveMadelineProtoSessions(string $domain): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM madelineproto_sessions 
             WHERE domain = ? AND status = "authorized" 
             ORDER BY account_first_name'
        );
        $stmt->execute([$domain]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
