<?php

declare(strict_types=1);

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
        }else{
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
        $hookToken = rtrim(
            strtr(base64_encode(json_encode($data)), '+/', '-_'),
            '='
        );
        
        $stmt = $this->pdo->prepare(
            'INSERT INTO bitrix_integration_tokens 
             (domain, member_id, refresh_token, access_token, client_id, 
              client_secret, hook_token, token_expires)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                refresh_token = VALUES(refresh_token),
                access_token = VALUES(access_token),
                hook_token = VALUES(hook_token),
                token_expires = VALUES(token_expires)'
        );
        
        return $stmt->execute([
            $data['domain'],
            $data['member_id'],
            $data['refresh_token'],
            $data['access_token'],
            $data['client_id'],
            $data['client_secret'],
            $hookToken,
            $data['expires'],
        ]);
    }
}