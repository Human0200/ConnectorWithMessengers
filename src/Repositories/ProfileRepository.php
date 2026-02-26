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
        $this->pdo->beginTransaction();
        try {
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

            $profileId = (int) $this->pdo->lastInsertId();

            // Для telegram_user — автоматически создаём запись сессии
            if ($messengerType === 'telegram_user') {
                $sessionId   = 'tg_' . bin2hex(random_bytes(12));
                $sessionFile = $sessionId . '.madeline';

                $this->pdo->prepare("
                    INSERT INTO madelineproto_sessions
                        (user_id, profile_id, domain, session_id, session_file, session_name, status)
                    VALUES (?, ?, '', ?, ?, ?, 'pending')
                ")->execute([$userId, $profileId, $sessionId, $sessionFile, trim($name)]);

                // Сохраняем session_id в extra профиля для быстрого доступа без JOIN
                $this->pdo->prepare("
                    UPDATE user_messenger_profiles SET extra = ? WHERE id = ?
                ")->execute([json_encode(['session_id' => $sessionId]), $profileId]);
            }

            $this->pdo->commit();
            return $this->findById($profileId, $userId);

        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
        if (!$row) return null;

        $profile = $this->decode($row);

        if ($profile['messenger_type'] === 'telegram_user') {
            $profile['session'] = $this->getSession($profile['id']);
        }

        return $profile;
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row) {
            $profile = $this->decode($row);
            if ($profile['messenger_type'] === 'telegram_user') {
                $profile['session'] = $this->getSession($profile['id']);
            }
            return $profile;
        }, $rows);
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
        $this->pdo->prepare(
            "DELETE FROM madelineproto_sessions WHERE profile_id = ? AND user_id = ?"
        )->execute([$id, $userId]);

        $stmt = $this->pdo->prepare(
            "DELETE FROM user_messenger_profiles WHERE id = ? AND user_id = ?"
        );
        return $stmt->execute([$id, $userId]);
    }

    // ─── MadelineProto sessions ──────────────────────────────────

    public function getSession(int $profileId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM madelineproto_sessions WHERE profile_id = ? LIMIT 1"
        );
        $stmt->execute([$profileId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getSessionBySessionId(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT ms.*, ump.user_id, ump.name AS profile_name, ump.messenger_type
            FROM madelineproto_sessions ms
            JOIN user_messenger_profiles ump ON ump.id = ms.profile_id
            WHERE ms.session_id = ?
            LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateSessionFile(string $sessionId, string $newSessionFile): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE madelineproto_sessions SET session_file = ?, updated_at = NOW() WHERE session_id = ?"
        );
        return $stmt->execute([$newSessionFile, $sessionId]);
    }

    public function updateSessionStatus(
        string  $sessionId,
        string  $status,
        ?int    $accountId        = null,
        ?string $accountUsername  = null,
        ?string $accountFirstName = null,
        ?string $accountLastName  = null,
        ?string $accountPhone     = null
    ): bool {
        // account_last_name может отсутствовать в старых схемах БД — проверяем динамически
        $hasLastName = $this->columnExists('madelineproto_sessions', 'account_last_name');
        $hasPhone    = $this->columnExists('madelineproto_sessions', 'account_phone');

        $sql = "UPDATE madelineproto_sessions SET
                status             = ?,
                account_id         = COALESCE(?, account_id),
                account_username   = COALESCE(?, account_username),
                account_first_name = COALESCE(?, account_first_name)";

        $params = [$status, $accountId, $accountUsername, $accountFirstName];

        if ($hasLastName) {
            $sql .= ", account_last_name = COALESCE(?, account_last_name)";
            $params[] = $accountLastName;
        }
        if ($hasPhone) {
            $sql .= ", account_phone = COALESCE(?, account_phone)";
            $params[] = $accountPhone;
        }

        $sql .= ", updated_at = NOW() WHERE session_id = ?";
        $params[] = $sessionId;

        return $this->pdo->prepare($sql)->execute($params);
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) return $cache[$key];

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        return $cache[$key] = (bool) $stmt->fetchColumn();
    }

    // ─── Connections ─────────────────────────────────────────────

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
            INSERT INTO profile_bitrix_connections (user_id, profile_id, domain, connector_id, openline_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                connector_id = VALUES(connector_id),
                openline_id  = COALESCE(VALUES(openline_id), openline_id),
                is_active    = 1, updated_at = NOW()
        ");
        return $stmt->execute([$userId, $profileId, $domain, $connectorId, $openlineId]);
    }

    public function deleteConnection(int $profileId, int $userId, string $domain): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM profile_bitrix_connections WHERE profile_id = ? AND user_id = ? AND domain = ?
        ");
        return $stmt->execute([$profileId, $userId, $domain]);
    }

    public function getStats(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(is_active = 1) AS active,
                   SUM(messenger_type = 'max') AS max_count,
                   SUM(messenger_type = 'telegram_bot') AS telegram_bot_count,
                   SUM(messenger_type = 'telegram_user') AS telegram_user_count
            FROM user_messenger_profiles WHERE user_id = ?
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