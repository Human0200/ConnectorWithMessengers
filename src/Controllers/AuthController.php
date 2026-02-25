<?php

declare(strict_types=1);

namespace BitrixTelegram\Controllers;

use BitrixTelegram\Repositories\UserRepository;
use BitrixTelegram\Middleware\AuthMiddleware;
use BitrixTelegram\Helpers\Logger;

class AuthController
{
    public function __construct(
        private UserRepository $userRepository,
        private AuthMiddleware $authMiddleware,
        private Logger         $logger
    ) {}

    /**
     * POST /auth/register
     * Body: { email, password, name }
     */
    public function register(array $data): array
    {
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $name     = trim($data['name'] ?? '');

        // Валидация
        $errors = $this->validateRegister($email, $password, $name);
        if ($errors) {
            return ['success' => false, 'errors' => $errors];
        }

        // Проверяем дубликат
        if ($this->userRepository->emailExists($email)) {
            return ['success' => false, 'errors' => ['email' => 'Этот email уже зарегистрирован']];
        }

        // Создаём пользователя
        $user = $this->userRepository->create($email, $password, $name);
        if (!$user) {
            $this->logger->error('Failed to create user', ['email' => $email]);
            return ['success' => false, 'errors' => ['general' => 'Ошибка создания аккаунта']];
        }

        // Сразу логиним
        $token = $this->userRepository->createSession(
            $user['id'],
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        $this->authMiddleware->setSessionCookie($token);

        $this->logger->info('User registered', ['user_id' => $user['id'], 'email' => $email]);

        return [
            'success' => true,
            'user'    => $this->safeUser($user),
            'token'   => $token,
        ];
    }

    /**
     * POST /auth/login
     * Body: { email, password }
     */
    public function login(array $data): array
    {
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            return ['success' => false, 'errors' => ['general' => 'Введите email и пароль']];
        }

        $user = $this->userRepository->verifyPassword($email, $password);

        if (!$user) {
            $this->logger->warning('Failed login attempt', ['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            return ['success' => false, 'errors' => ['general' => 'Неверный email или пароль']];
        }

        $token = $this->userRepository->createSession(
            $user['id'],
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        $this->authMiddleware->setSessionCookie($token);

        $this->logger->info('User logged in', ['user_id' => $user['id']]);

        return [
            'success' => true,
            'user'    => $this->safeUser($user),
            'token'   => $token,
        ];
    }

    /**
     * POST /auth/logout
     */
    public function logout(): array
    {
        $user = $this->authMiddleware->user();

        if ($user) {
            $this->userRepository->deleteSession($user['token']);
            $this->logger->info('User logged out', ['user_id' => $user['id']]);
        }

        $this->authMiddleware->clearSessionCookie();

        return ['success' => true];
    }

    /**
     * GET /auth/me
     * Получить текущего пользователя
     */
    public function me(): array
    {
        $user = $this->authMiddleware->user();

        if (!$user) {
            return ['success' => false, 'error' => 'Unauthorized'];
        }

        $full = $this->userRepository->findById($user['id']);

    $full['api_token'] = $this->userRepository->getOrCreateApiToken($user['id']);
    return ['success' => true, 'user' => $this->safeUser($full)];
    }


    public function regenToken(): array
{
    $user  = $this->authMiddleware->require();
    $token = $this->userRepository->regenApiToken($user['id']);
    return ['success' => true, 'api_token' => $token];
}

    /**
     * PATCH /auth/profile
     * Body: { name } или { current_password, new_password }
     */
    public function updateProfile(array $data): array
    {
        $user = $this->authMiddleware->require();

        // Обновление имени
        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (mb_strlen($name) < 2) {
                return ['success' => false, 'errors' => ['name' => 'Минимум 2 символа']];
            }
            $this->userRepository->updateName($user['id'], $name);
        }

        // Смена пароля
        if (isset($data['new_password'])) {
            $currentPassword = $data['current_password'] ?? '';
            $newPassword     = $data['new_password'];

            $verified = $this->userRepository->verifyPassword($user['email'], $currentPassword);
            if (!$verified) {
                return ['success' => false, 'errors' => ['current_password' => 'Неверный текущий пароль']];
            }

            $pwErrors = $this->validatePassword($newPassword);
            if ($pwErrors) {
                return ['success' => false, 'errors' => ['new_password' => $pwErrors]];
            }

            $this->userRepository->updatePassword($user['id'], $newPassword);

            // Разлогиниваем все другие сессии
            $this->userRepository->deleteAllSessions($user['id']);

            // Создаём новую сессию
            $token = $this->userRepository->createSession(
                $user['id'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
            $this->authMiddleware->setSessionCookie($token);
        }

        $updated = $this->userRepository->findById($user['id']);

        return [
            'success' => true,
            'user'    => $this->safeUser($updated),
        ];
    }

    // ─── Validation ──────────────────────────────────────────────

    private function validateRegister(string $email, string $password, string $name): array
    {
        $errors = [];

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Введите корректный email';
        }

        $pwError = $this->validatePassword($password);
        if ($pwError) {
            $errors['password'] = $pwError;
        }

        if (empty($name) || mb_strlen($name) < 2) {
            $errors['name'] = 'Введите имя (минимум 2 символа)';
        }

        return $errors;
    }

    private function validatePassword(string $password): ?string
    {
        if (mb_strlen($password) < 8) {
            return 'Пароль должен быть не менее 8 символов';
        }
        return null;
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function safeUser(?array $user): ?array
    {
        if (!$user) return null;

        unset($user['password_hash']);
        return $user;
    }
}