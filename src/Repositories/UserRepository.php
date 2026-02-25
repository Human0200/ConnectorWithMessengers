<?php

declare(strict_types=1);

namespace BitrixTelegram\Repositories;

use PDO;

class UserRepository
{
    public function __construct(private PDO $pdo) {}

    // ─── Users ──────────────────────────────────────────────────

    public function create(string $email, string $password, string $name = ''): ?array
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (email, password_hash, name)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            strtolower(trim($email)),
            password_hash($password, PASSWORD_BCRYPT),
            trim($name),
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([strtolower(trim($email))]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([strtolower(trim($email))]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function verifyPassword(string $email, string $password): ?array
    {
        $user = $this->findByEmail($email);
        if (!$user) return null;
        if (!password_verify($password, $user['password_hash'])) return null;
        if (!$user['is_active']) return null;
        return $user;
    }
    public function getOrCreateApiToken(int $userId): string
{
    $user = $this->findById($userId);
    if (!empty($user['api_token'])) return $user['api_token'];
    return $this->regenApiToken($userId);
}

public function regenApiToken(int $userId): string
{
    $token = 'btg_' . bin2hex(random_bytes(32));
    $this->pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?")
              ->execute([$token, $userId]);
    return $token;
}

    public function updateName(int $userId, string $name): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
        return $stmt->execute([trim($name), $userId]);
    }

    public function updatePassword(int $userId, string $newPassword): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        return $stmt->execute([password_hash($newPassword, PASSWORD_BCRYPT), $userId]);
    }

    public function getAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, email, name, is_active, is_admin, created_at
            FROM users ORDER BY created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Sessions ────────────────────────────────────────────────

    public function createSession(int $userId, string $ip = '', string $userAgent = ''): string
    {
        $this->cleanOldSessions($userId);

        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $stmt = $this->pdo->prepare("
            INSERT INTO user_sessions (user_id, token, ip, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $token, $ip, $userAgent, $expiresAt]);

        return $token;
    }

    public function findSession(string $token): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, u.id AS user_id, u.email, u.name, u.is_admin, u.is_active
            FROM user_sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.token = ?
              AND s.expires_at > NOW()
              AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteSession(string $token): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE token = ?");
        return $stmt->execute([$token]);
    }

    public function deleteAllSessions(int $userId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        return $stmt->execute([$userId]);
    }

    private function cleanOldSessions(int $userId): void
    {
        $this->pdo->prepare("DELETE FROM user_sessions WHERE user_id = ? AND expires_at < NOW()")
                  ->execute([$userId]);

        $this->pdo->prepare("
            DELETE FROM user_sessions
            WHERE user_id = ?
              AND id NOT IN (
                  SELECT id FROM (
                      SELECT id FROM user_sessions
                      WHERE user_id = ?
                      ORDER BY created_at DESC
                      LIMIT 4
                  ) t
              )
        ")->execute([$userId, $userId]);
    }
}