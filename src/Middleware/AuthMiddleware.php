<?php

declare(strict_types=1);

namespace BitrixTelegram\Middleware;

use BitrixTelegram\Repositories\UserRepository;

class AuthMiddleware
{
    private const COOKIE_NAME = 'btg_session';

    public function __construct(private UserRepository $userRepository) {}

    /**
     * Получить текущего авторизованного пользователя.
     * Читает токен из cookie или заголовка Authorization: Bearer
     */
    public function user(): ?array
    {
        $token = $this->extractToken();
        if (!$token) return null;

        $session = $this->userRepository->findSession($token);
        if (!$session) return null;

        return [
            'id'       => (int) $session['user_id'],
            'email'    => $session['email'],
            'name'     => $session['name'],
            'is_admin' => (bool) $session['is_admin'],
            'token'    => $token,
        ];
    }

    /**
     * Требовать авторизацию — если нет, вернуть 401 и остановить выполнение
     */
    public function require(): array
    {
        $user = $this->user();
        if (!$user) {
            $this->sendUnauthorized();
        }
        return $user;
    }

    /**
     * Требовать права администратора
     */
    public function requireAdmin(): array
    {
        $user = $this->require();
        if (!$user['is_admin']) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden', 'code' => 403]);
            exit;
        }
        return $user;
    }

    /**
     * Установить cookie сессии
     */
    public function setSessionCookie(string $token): void
    {
        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires'  => time() + 60 * 60 * 24 * 30,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]
        );
    }

    /**
     * Удалить cookie сессии
     */
    public function clearSessionCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // ─── Private ─────────────────────────────────────────────────

    private function extractToken(): ?string
    {
        // 1. Cookie
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            return $_COOKIE[self::COOKIE_NAME];
        }

        // 2. Authorization: Bearer (для API-клиентов)
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? apache_request_headers()['Authorization']
            ?? null;

        if ($header && str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        return null;
    }

    private function sendUnauthorized(): never
    {
        // Если запрос JSON — отвечаем JSON
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json') || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized', 'code' => 401]);
        } else {
            // Иначе редиректим на страницу логина
            header('Location: /login.php');
        }
        exit;
    }
}