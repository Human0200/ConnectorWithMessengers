<?php

declare(strict_types=1);

namespace BitrixTelegram\Repositories;

use PDO;

class ProfileRepository
{
    public function __construct(private PDO $pdo) {}

    // ─── Profiles ────────────────────────────────────────────────

    public function create(int $userId, string $messengerType, string $name, ?string $token = null, array $extra = []): ?array
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO user_messenger_profiles (user_id, messenger_type, name, token, extra)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $messengerType,
            trim($name),
            $token,
            $extra ? json_encode($extra) : null,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId(), $userId);
    }

    public function findById(int $id, ?int $userId = null): ?array
    {
        $sql    = "SELECT * FROM user_messenger_profiles WHERE id = ?";
        $params = [$id];

        if ($userId !== null) {
            $sql    .= " AND user_id = ?";
            $params[] = $userId;
        }

        $stmt = $this->pdo->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode($row) : null;
    }

    public function getByUser(int $userId, ?string $messengerType = null): array
    {
        $sql    = "SELECT * FROM user_messenger_profiles WHERE user_id = ?";
        $params = [$userId];

        if ($messengerType) {
            $sql    .= " AND messenger_type = ?";
            $params[] = $messengerType;
        }

        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'decode'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function update(int $id, int $userId, array $fields): bool
    {
        $allowed = ['name', 'token', 'extra', 'is_active'];
        $set     = [];
        $params  = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $fields)) continue;
            $value = $fields[$field];
            if ($field === 'extra' && is_array($value)) {
                $value = json_encode($value);
            }
            $set[]   = "`$field` = ?";
            $params[] = $value;
        }

        if (empty($set)) return false;

        $params[] = $id;
        $params[] = $userId;

        $stmt = $this->pdo->prepare("
            UPDATE user_messenger_profiles
            SET " . implode(', ', $set) . "
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute($params);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM user_messenger_profiles WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([$id, $userId]);
    }

    // ─── Bitrix connections ──────────────────────────────────────

    public function getConnections(int $profileId, int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT pbc.*, bit.member_id, bit.token_expires
            FROM profile_bitrix_connections pbc
            LEFT JOIN bitrix_integration_tokens bit ON bit.domain = pbc.domain
            WHERE pbc.profile_id = ? AND pbc.user_id = ?
            ORDER BY pbc.created_at DESC
        ");
        $stmt->execute([$profileId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveConnection(int $userId, int $profileId, string $domain, ?string $connectorId = null, ?int $openlineId = null): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO profile_bitrix_connections
                (user_id, profile_id, domain, connector_id, openline_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                connector_id = VALUES(connector_id),
                openline_id  = COALESCE(VALUES(openline_id), openline_id),
                is_active    = 1,
                updated_at   = NOW()
        ");
        return $stmt->execute([$userId, $profileId, $domain, $connectorId, $openlineId]);
    }

    public function updateOpenline(int $profileId, string $domain, int $openlineId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE profile_bitrix_connections
            SET openline_id = ?, updated_at = NOW()
            WHERE profile_id = ? AND domain = ?
        ");
        return $stmt->execute([$openlineId, $profileId, $domain]);
    }

    public function deleteConnection(int $profileId, int $userId, string $domain): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM profile_bitrix_connections
            WHERE profile_id = ? AND user_id = ? AND domain = ?
        ");
        return $stmt->execute([$profileId, $userId, $domain]);
    }

    /**
     * Найти профиль по домену — для входящих вебхуков
     */
    public function findByDomainAndType(string $domain, string $messengerType): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT p.*, pbc.connector_id, pbc.openline_id, pbc.domain AS connected_domain
            FROM profile_bitrix_connections pbc
            JOIN user_messenger_profiles p ON p.id = pbc.profile_id
            WHERE pbc.domain = ?
              AND p.messenger_type = ?
              AND pbc.is_active = 1
              AND p.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$domain, $messengerType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode($row) : null;
    }

    public function getStats(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(is_active = 1) AS active,
                SUM(messenger_type = 'max') AS max_count,
                SUM(messenger_type = 'telegram_bot') AS telegram_bot_count,
                SUM(messenger_type = 'telegram_user') AS telegram_user_count
            FROM user_messenger_profiles
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function decode(array $row): array
    {
        if (isset($row['extra']) && is_string($row['extra'])) {
            $row['extra'] = json_decode($row['extra'], true) ?? [];
        }
        return $row;
    }
}