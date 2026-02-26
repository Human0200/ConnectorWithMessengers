<?php

declare(strict_types=1);

namespace BitrixTelegram\Services;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Helpers\Logger;
use RuntimeException;

class MadelineProtoService
{
    private string $sessionPath;
    private array $instances = [];
    private ?ProfileRepository $profileRepository = null;

    public function __construct(
        private TokenRepository $tokenRepository,
        private Logger $logger,
        private int $apiId,
        private string $apiHash,
        ?string $sessionPath = null
    ) {
        $this->sessionPath = $sessionPath ?? __DIR__ . '/../../storage/sessions/';
        $this->initializeSessionDirectory();
    }

    /**
     * Передать ProfileRepository для работы с профилями ЛК.
     * Вызывается из qr_auth.php после создания сервиса.
     */
    public function setProfileRepository(ProfileRepository $profileRepository): void
    {
        $this->profileRepository = $profileRepository;
    }

    // ─── Init ─────────────────────────────────────────────────────

    private function initializeSessionDirectory(): void
    {
        if (!is_dir($this->sessionPath)) {
            mkdir($this->sessionPath, 0755, true);
        }
    }

    private function buildSettings(): Settings
    {
        $settings = new Settings;
        $appInfo  = new AppInfo;
        $appInfo->setApiId($this->apiId);
        $appInfo->setApiHash($this->apiHash);
        $settings->setAppInfo($appInfo);
        return $settings;
    }

    private function resolveSessionFile(string $sessionFile): string
    {
        if (str_starts_with($sessionFile, '/') || preg_match('/^[A-Z]:/i', $sessionFile)) {
            return $sessionFile;
        }
        return $this->sessionPath . basename($sessionFile);
    }

    // ─── Новые методы (для профилей ЛК, через ProfileRepository) ──

    /**
     * Найти сессию по session_id без domain (использует ProfileRepository)
     */
    private function getSessionInfoBySessionId(string $sessionId): ?array
    {
        if ($this->profileRepository) {
            return $this->profileRepository->getSessionBySessionId($sessionId);
        }
        return null;
    }

    /**
     * Получить или создать инстанс по profile_id + session_id.
     * Используется в qr_auth.php.
     */
    public function createOrGetInstanceByProfile(int $profileId, string $sessionId): API
    {
        $cacheKey = 'profile_' . $profileId . '_' . $sessionId;

        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }

        $sessionInfo = $this->getSessionInfoBySessionId($sessionId);

        if (!$sessionInfo) {
            throw new RuntimeException("Сессия '$sessionId' не найдена в базе данных");
        }

        $sessionFile = $this->resolveSessionFile($sessionInfo['session_file']);

        $this->logger->info('createOrGetInstanceByProfile', [
            'profile_id'   => $profileId,
            'session_id'   => $sessionId,
            'session_file' => $sessionFile,
            'file_exists'  => file_exists($sessionFile) ? 'yes' : 'no',
        ]);

        $instance = new API($sessionFile, $this->buildSettings());
        $this->instances[$cacheKey] = $instance;

        return $instance;
    }

    /**
     * Получить инстанс из кеша (не создаёт новый).
     */
    public function getInstanceByProfile(int $profileId, string $sessionId): ?API
    {
        return $this->instances['profile_' . $profileId . '_' . $sessionId] ?? null;
    }

    /**
     * Удалить файл сессии и очистить кеш инстанса.
     * Вызывается когда qrLogin() вернул null но getSelf() тоже не работает.
     */
    public function resetSessionFile(int $profileId, string $sessionId): void
    {
        $cacheKey    = 'profile_' . $profileId . '_' . $sessionId;
        $sessionInfo = $this->getSessionInfoBySessionId($sessionId);

        if ($sessionInfo) {
            $sessionFile = $this->resolveSessionFile($sessionInfo['session_file']);
            if (file_exists($sessionFile)) {
                @unlink($sessionFile);
                $this->logger->info('Session file deleted for reset', ['file' => $sessionFile]);
            }
            // Удаляем также .session.lock если есть
            if (file_exists($sessionFile . '.lock')) {
                @unlink($sessionFile . '.lock');
            }
        }

        unset($this->instances[$cacheKey]);
    }

    /**
     * Обновить статус сессии профиля (через ProfileRepository).
     */
    public function updateProfileSessionStatus(
        string  $sessionId,
        string  $status,
        ?int    $accountId        = null,
        ?string $accountUsername  = null,
        ?string $accountFirstName = null,
        ?string $accountLastName  = null
    ): bool {
        if ($this->profileRepository) {
            return $this->profileRepository->updateSessionStatus(
                $sessionId, $status,
                $accountId, $accountUsername,
                $accountFirstName, $accountLastName
            );
        }
        return false;
    }

    // ─── Старые методы (для обратной совместимости) ───────────────

    public function generateSessionId(?string $customId = null): string
    {
        return $customId ?? 'session_' . bin2hex(random_bytes(8));
    }

    public function getSessionFile(string $domain, string $sessionId): string
    {
        $safeDomain    = preg_replace('/[^a-z0-9_\-]/i', '_', $domain);
        $safeSessionId = preg_replace('/[^a-z0-9_\-]/i', '_', $sessionId);
        return $this->sessionPath . 'madeline_' . md5($safeDomain . '_' . $safeSessionId) . '.session';
    }

    public function getSessionInfo(string $domain, string $sessionId): ?array
    {
        return $this->tokenRepository->getMadelineProtoSession($domain, $sessionId);
    }

    public function getInstance(string $domain, string $sessionId): ?API
    {
        $cacheKey = $domain . '_' . $sessionId;

        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }

        $sessionInfo = $this->tokenRepository->getMadelineProtoSession($domain, $sessionId);

        if (!$sessionInfo || $sessionInfo['status'] !== 'authorized') {
            $this->logger->warning('MadelineProto session not authorized', [
                'domain'     => $domain,
                'session_id' => $sessionId,
            ]);
            return null;
        }

        $sessionFile = $this->resolveSessionFile(
            $sessionInfo['session_file'] ?? $this->getSessionFile($domain, $sessionId)
        );

        if (!file_exists($sessionFile)) {
            $this->logger->error('Session file not found', [
                'domain'     => $domain,
                'session_id' => $sessionId,
                'file'       => $sessionFile,
            ]);
            $this->tokenRepository->updateSessionStatus($domain, $sessionId, 'expired');
            return null;
        }

        try {
            $instance = new API($sessionFile, $this->buildSettings());
            $me       = $instance->getSelf();

            if (!$me) {
                $this->logger->error('MadelineProto session invalid', [
                    'domain' => $domain, 'session_id' => $sessionId,
                ]);
                $this->tokenRepository->updateSessionStatus($domain, $sessionId, 'expired');
                return null;
            }

            $this->instances[$cacheKey] = $instance;

            $this->logger->info('MadelineProto instance created', [
                'domain'     => $domain,
                'session_id' => $sessionId,
                'account'    => $me['username'] ?? $me['id'] ?? 'unknown',
            ]);

            return $instance;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create MadelineProto instance', [
                'domain'     => $domain,
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function createOrGetInstance(string $domain, string $sessionId): API
    {
        $cacheKey = $domain . '_' . $sessionId;

        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }

        $sessionInfo = $this->tokenRepository->getMadelineProtoSession($domain, $sessionId);

        if (!$sessionInfo) {
            throw new RuntimeException("Session not found: $sessionId");
        }

        $sessionFile = $this->resolveSessionFile($sessionInfo['session_file']);

        $this->logger->info('createOrGetInstance', [
            'domain'       => $domain,
            'session_id'   => $sessionId,
            'session_file' => $sessionFile,
            'file_exists'  => file_exists($sessionFile) ? 'yes' : 'no',
        ]);

        $instance = new API($sessionFile, $this->buildSettings());
        $this->instances[$cacheKey] = $instance;

        return $instance;
    }

    public function createSession(
        string $domain,
        string $sessionId,
        string $sessionName = 'Новая сессия'
    ): array {
        $sessionFile = $this->getSessionFile($domain, $sessionId);

        if (file_exists($sessionFile)) {
            @unlink($sessionFile);
        }

        $this->tokenRepository->saveMadelineProtoSession(
            $domain, $sessionId, $sessionFile, $sessionName,
            null, null, null, 'pending'
        );

        return [
            'success'      => true,
            'session_id'   => $sessionId,
            'session_name' => $sessionName,
            'session_file' => $sessionFile,
        ];
    }

    public function sendMessage(string $chatId, string $text, string $domain, string $sessionId): array
    {
        $mp = $this->getInstance($domain, $sessionId);

        if (!$mp) {
            return ['success' => false, 'error' => 'MadelineProto not initialized for session: ' . $sessionId];
        }

        try {
            $result = $mp->messages->sendMessage([
                'peer'       => $this->extractTelegramId($chatId),
                'message'    => $text,
                'parse_mode' => 'HTML',
            ]);

            return ['success' => true, 'data' => $result, 'message_id' => $result['id'] ?? null];

        } catch (\Exception $e) {
            $this->logger->error('MadelineProto sendMessage error', [
                'domain'     => $domain,
                'session_id' => $sessionId,
                'chat_id'    => $chatId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getDomainSessions(string $domain): array
    {
        return $this->tokenRepository->getMadelineProtoSessions($domain);
    }

    public function deleteSession(string $domain, string $sessionId): bool
    {
        $sessionFile = $this->getSessionFile($domain, $sessionId);
        if (file_exists($sessionFile)) {
            @unlink($sessionFile);
        }

        $cacheKey = $domain . '_' . $sessionId;
        unset($this->instances[$cacheKey]);

        return $this->tokenRepository->deleteMadelineProtoSession($domain, $sessionId);
    }

    public function checkAuthStatus(string $domain, string $sessionId): array
    {
        try {
            $sessionFile = $this->getSessionFile($domain, $sessionId);

            if (!file_exists($sessionFile)) {
                return ['success' => false, 'status' => 'no_session', 'message' => 'Session file not found'];
            }

            $instance = new API($sessionFile, $this->buildSettings());
            $me       = null;

            try { $me = $instance->getSelf(); } catch (\Exception $e) {}

            if ($me) {
                $info = $this->tokenRepository->getMadelineProtoSession($domain, $sessionId);
                $this->tokenRepository->saveMadelineProtoSession(
                    $domain, $sessionId, $sessionFile,
                    $info['session_name'] ?? 'Session',
                    $me['id'] ?? null, $me['username'] ?? null,
                    $me['first_name'] ?? null, 'authorized'
                );

                return [
                    'success' => true,
                    'status'  => 'authorized',
                    'account' => [
                        'id'         => $me['id'] ?? null,
                        'first_name' => $me['first_name'] ?? null,
                        'last_name'  => $me['last_name'] ?? null,
                        'username'   => $me['username'] ?? null,
                        'phone'      => $me['phone'] ?? null,
                    ],
                ];
            }

            return ['success' => true, 'status' => 'pending', 'message' => 'Waiting for authorization'];

        } catch (\Exception $e) {
            $this->logger->error('Failed to check auth status', [
                'domain'     => $domain,
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'status' => 'error', 'error' => $e->getMessage()];
        }
    }

    public function checkSessionAuth(string $domain, string $sessionId): array
    {
        return $this->checkAuthStatus($domain, $sessionId);
    }

    public function updateSessionName(string $domain, string $sessionId, string $sessionName): bool
    {
        return $this->tokenRepository->updateSessionName($domain, $sessionId, $sessionName);
    }

    public function generateAuthLink(string $domain, string $sessionId): string
    {
        $authToken = bin2hex(random_bytes(16));
        $loginUrl  = 'tg://login?token=' . $authToken;

        file_put_contents(
            $this->sessionPath . 'auth_' . $authToken . '.json',
            json_encode([
                'domain'     => $domain,
                'session_id' => $sessionId,
                'login_url'  => $loginUrl,
                'created_at' => time(),
            ])
        );

        return $loginUrl;
    }

    public function startInteractiveAuth(string $domain, string $sessionId): array
    {
        try {
            $sessionFile = $this->getSessionFile($domain, $sessionId);

            if (file_exists($sessionFile)) {
                @unlink($sessionFile);
            }

            $instance = new API($sessionFile, $this->buildSettings());
            $instance->start();

            $me = $instance->getSelf();
            if (!$me) {
                throw new RuntimeException('Authorization completed but failed to get account info');
            }

            $current     = $this->tokenRepository->getMadelineProtoSession($domain, $sessionId);
            $sessionName = $current['session_name'] ?? 'QR Session ' . date('Y-m-d H:i:s');

            $this->tokenRepository->saveMadelineProtoSession(
                $domain, $sessionId, $sessionFile, $sessionName,
                $me['id'] ?? null, $me['username'] ?? null,
                $me['first_name'] ?? null, 'authorized'
            );

            return [
                'success'    => true,
                'status'     => 'authorized',
                'account'    => [
                    'id'         => $me['id'] ?? null,
                    'first_name' => $me['first_name'] ?? null,
                    'last_name'  => $me['last_name'] ?? null,
                    'username'   => $me['username'] ?? null,
                    'phone'      => $me['phone'] ?? null,
                ],
                'session_id' => $sessionId,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to complete interactive auth', [
                'domain'     => $domain,
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function extractTelegramId(string $chatId): string
    {
        foreach (['tguser_chat_', 'tguser_channel_', 'tguser_'] as $prefix) {
            if (str_starts_with($chatId, $prefix)) {
                return substr($chatId, strlen($prefix));
            }
        }
        return $chatId;
    }
}