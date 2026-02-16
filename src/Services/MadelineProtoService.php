<?php

declare(strict_types=1);

namespace BitrixTelegram\Services;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;
use RuntimeException;

class MadelineProtoService
{
    private TokenRepository $tokenRepository;
    private Logger $logger;
    private string $sessionPath;
    private array $instances = [];
    private int $apiId;
    private string $apiHash;

    public function __construct(
        TokenRepository $tokenRepository,
        Logger $logger,
        int $apiId,
        string $apiHash,
        ?string $sessionPath = null
    ) {
        $this->tokenRepository = $tokenRepository;
        $this->logger = $logger;
        $this->apiId = $apiId;
        $this->apiHash = $apiHash;
        $this->sessionPath = $sessionPath ?? __DIR__ . '/../../storage/sessions/';
        
        $this->initializeSessionDirectory();
    }

    private function initializeSessionDirectory(): void
    {
        if (!is_dir($this->sessionPath)) {
            mkdir($this->sessionPath, 0755, true);
        }
    }

    public function generateSessionId(?string $customId = null): string
    {
        return $customId ?? 'session_' . bin2hex(random_bytes(8));
    }

    public function getSessionFile(string $domain, string $sessionId): string
    {
        $safeDomain = preg_replace('/[^a-z0-9_\-]/i', '_', $domain);
        $safeSessionId = preg_replace('/[^a-z0-9_\-]/i', '_', $sessionId);
        return $this->sessionPath . 'madeline_' . md5($safeDomain . '_' . $safeSessionId) . '.session';
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
                'domain' => $domain,
                'session_id' => $sessionId
            ]);
            return null;
        }

        $sessionFile = $sessionInfo['session_file'] ?? $this->getSessionFile($domain, $sessionId);

        if (!file_exists($sessionFile)) {
            $this->logger->error('Session file not found', [
                'domain' => $domain,
                'session_id' => $sessionId,
                'file' => $sessionFile
            ]);
            
            $this->tokenRepository->updateSessionStatus($domain, $sessionId, 'expired');
            return null;
        }

        try {
            $settings = new Settings;
            $appInfo = new AppInfo;
            $appInfo->setApiId($this->apiId);
            $appInfo->setApiHash($this->apiHash);
            $settings->setAppInfo($appInfo);

            $madelineProto = new API($sessionFile, $settings);
            
            $me = $madelineProto->getSelf();
            
            if (!$me) {
                $this->logger->error('MadelineProto session invalid', [
                    'domain' => $domain,
                    'session_id' => $sessionId
                ]);
                $this->tokenRepository->updateSessionStatus($domain, $sessionId, 'expired');
                return null;
            }

            $this->instances[$cacheKey] = $madelineProto;
            
            $this->logger->info('MadelineProto instance created', [
                'domain' => $domain,
                'session_id' => $sessionId,
                'account' => $me['username'] ?? $me['id'] ?? 'unknown'
            ]);

            return $madelineProto;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create MadelineProto instance', [
                'domain' => $domain,
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
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
            $domain,
            $sessionId,
            $sessionFile,
            $sessionName,
            null,
            null,
            null,
            'pending'
        );

        return [
            'success' => true,
            'session_id' => $sessionId,
            'session_name' => $sessionName,
            'session_file' => $sessionFile
        ];
    }

    /**
     * Начать QR-авторизацию (интерактивный режим)
     * Этот метод НЕ возвращает QR-код, а запускает интерактивную авторизацию MadelineProto
     * 
     * @param string $domain Домен портала
     * @param string $sessionId ID сессии
     * @return array Результат авторизации
     */
    public function startInteractiveAuth(string $domain, string $sessionId): array
    {
        try {
            $sessionFile = $this->getSessionFile($domain, $sessionId);
            
            // Удаляем старый файл сессии если есть
            if (file_exists($sessionFile)) {
                @unlink($sessionFile);
            }

            // Создаем настройки
            $settings = new Settings;
            $appInfo = new AppInfo;
            $appInfo->setApiId($this->apiId);
            $appInfo->setApiHash($this->apiHash);
            $settings->setAppInfo($appInfo);

            $this->logger->info('Starting interactive auth', [
                'domain' => $domain,
                'session_id' => $sessionId
            ]);

            // Создаем экземпляр MadelineProto
            $madelineProto = new API($sessionFile, $settings);
            
            // Запускаем интерактивную авторизацию
            // MadelineProto сам покажет QR-код в консоли и будет ждать сканирования
            $madelineProto->start();
            
            // Если дошли сюда - авторизация успешна
            $me = $madelineProto->getSelf();
            
            if (!$me) {
                throw new RuntimeException('Authorization completed but failed to get account info');
            }

            // Сохраняем информацию о сессии
            $currentSession = $this->tokenRepository->getMadelineProtoSession($domain, $sessionId);
            $sessionName = $currentSession['session_name'] ?? 'QR Session ' . date('Y-m-d H:i:s');
            
            $this->tokenRepository->saveMadelineProtoSession(
                $domain,
                $sessionId,
                $sessionFile,
                $sessionName,
                $me['id'] ?? null,
                $me['username'] ?? null,
                $me['first_name'] ?? null,
                'authorized'
            );

            $this->logger->info('Interactive auth completed', [
                'domain' => $domain,
                'session_id' => $sessionId,
                'account' => $me['username'] ?? $me['id']
            ]);

            return [
                'success' => true,
                'status' => 'authorized',
                'account' => [
                    'id' => $me['id'] ?? null,
                    'first_name' => $me['first_name'] ?? null,
                    'last_name' => $me['last_name'] ?? null,
                    'username' => $me['username'] ?? null,
                    'phone' => $me['phone'] ?? null
                ],
                'session_id' => $sessionId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to complete interactive auth', [
                'domain' => $domain,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Проверить статус авторизации сессии
     * 
     * @param string $domain Домен портала
     * @param string $sessionId ID сессии
     * @return array Статус авторизации
     */
    public function checkAuthStatus(string $domain, string $sessionId): array
    {
        try {
            $sessionFile = $this->getSessionFile($domain, $sessionId);
            
            if (!file_exists($sessionFile)) {
                return [
                    'success' => false,
                    'status' => 'no_session',
                    'message' => 'Session file not found'
                ];
            }

            // Создаем настройки
            $settings = new Settings;
            $appInfo = new AppInfo;
            $appInfo->setApiId($this->apiId);
            $appInfo->setApiHash($this->apiHash);
            $settings->setAppInfo($appInfo);

            // Подключаемся к сессии
            $madelineProto = new API($sessionFile, $settings);
            
            // Пытаемся получить информацию о пользователе
            $me = null;
            try {
                $me = $madelineProto->getSelf();
            } catch (\Exception $e) {
                $this->logger->debug('Not authorized yet', [
                    'domain' => $domain,
                    'session_id' => $sessionId,
                    'error' => $e->getMessage()
                ]);
            }

            if ($me) {
                // Авторизация успешна!
                $currentSessionInfo = $this->tokenRepository->getMadelineProtoSession($domain, $sessionId);
                $currentSessionName = $currentSessionInfo['session_name'] ?? 'Session ' . date('Y-m-d H:i:s');
                
                $this->tokenRepository->saveMadelineProtoSession(
                    $domain,
                    $sessionId,
                    $sessionFile,
                    $currentSessionName,
                    $me['id'] ?? null,
                    $me['username'] ?? null,
                    $me['first_name'] ?? null,
                    'authorized'
                );

                $this->logger->info('Auth check: authorized', [
                    'domain' => $domain,
                    'session_id' => $sessionId,
                    'account' => $me['username'] ?? $me['id']
                ]);

                return [
                    'success' => true,
                    'status' => 'authorized',
                    'account' => [
                        'id' => $me['id'] ?? null,
                        'first_name' => $me['first_name'] ?? null,
                        'last_name' => $me['last_name'] ?? null,
                        'username' => $me['username'] ?? null,
                        'phone' => $me['phone'] ?? null
                    ]
                ];
            }

            // Еще не авторизован
            return [
                'success' => true,
                'status' => 'pending',
                'message' => 'Waiting for authorization'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to check auth status', [
                'domain' => $domain,
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    public function generateAuthLink(string $domain, string $sessionId): string
    {
        $authToken = bin2hex(random_bytes(16));
        $loginUrl = "tg://login?token=" . $authToken;
        
        $authFile = $this->sessionPath . 'auth_' . $authToken . '.json';
        $authData = [
            'domain' => $domain,
            'session_id' => $sessionId,
            'login_url' => $loginUrl,
            'created_at' => time()
        ];
        
        file_put_contents($authFile, json_encode($authData));
        
        return $loginUrl;
    }

    public function checkSessionAuth(string $domain, string $sessionId): array
    {
        return $this->checkAuthStatus($domain, $sessionId);
    }

    public function sendMessage(
        string $chatId, 
        string $text, 
        string $domain, 
        string $sessionId
    ): array {
        $mp = $this->getInstance($domain, $sessionId);
        
        if (!$mp) {
            return [
                'success' => false, 
                'error' => 'MadelineProto not initialized for session: ' . $sessionId
            ];
        }

        try {
            $cleanChatId = $this->extractTelegramId($chatId);
            
            $result = $mp->messages->sendMessage([
                'peer' => $cleanChatId,
                'message' => $text,
                'parse_mode' => 'HTML'
            ]);

            return [
                'success' => true, 
                'data' => $result,
                'message_id' => $result['id'] ?? null
            ];
        } catch (\Exception $e) {
            $this->logger->error('MadelineProto sendMessage error', [
                'domain' => $domain,
                'session_id' => $sessionId,
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getSessionInfo(string $domain, string $sessionId): ?array
    {
        return $this->tokenRepository->getMadelineProtoSession($domain, $sessionId);
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
        if (isset($this->instances[$cacheKey])) {
            unset($this->instances[$cacheKey]);
        }
        
        return $this->tokenRepository->deleteMadelineProtoSession($domain, $sessionId);
    }

public function createOrGetInstance(string $domain, string $sessionId): ?\danog\MadelineProto\API
{
    try {
        $cacheKey = $domain . '_' . $sessionId;
        
        // Пытаемся получить из кеша
        if (isset($this->instances[$cacheKey])) {
            $this->logger->info('Returning cached instance', ['cache_key' => $cacheKey]);
            return $this->instances[$cacheKey];
        }
        
        // Получаем информацию о сессии
        $sessionInfo = $this->tokenRepository->getMadelineProtoSession($domain, $sessionId);
        
        if (!$sessionInfo) {
            $this->logger->error('Session not found in database', [
                'domain' => $domain,
                'session_id' => $sessionId
            ]);
            throw new \Exception("Session not found in database");
        }
        
        $this->logger->info('Session info retrieved', [
            'session_file' => $sessionInfo['session_file'] ?? 'null',
            'status' => $sessionInfo['status'] ?? 'unknown'
        ]);
        
        $sessionFile = $sessionInfo['session_file'];
        
        // Проверяем, является ли это абсолютным путем
        if (!str_starts_with($sessionFile, '/') && !preg_match('/^[A-Z]:/i', $sessionFile)) {
            // Относительный путь - добавляем базовый путь
            $sessionFile = $this->sessionPath . basename($sessionFile);
        }
        
        $this->logger->info('Creating MadelineProto instance', [
            'session_file' => $sessionFile,
            'file_exists' => file_exists($sessionFile) ? 'yes' : 'no',
            'session_path' => $this->sessionPath
        ]);
        
        // Создаем настройки (правильный способ для MadelineProto)
        $settings = new Settings;
        $appInfo = new AppInfo;
        $appInfo->setApiId($this->apiId);
        $appInfo->setApiHash($this->apiHash);
        $settings->setAppInfo($appInfo);
        
        $this->logger->info('Settings created, initializing API...');
        
        // Создаем экземпляр
        $instance = new API($sessionFile, $settings);
        
        $this->logger->info('API instance created successfully');
        
        // Сохраняем в кеш
        $this->instances[$cacheKey] = $instance;
        
        return $instance;
        
    } catch (\Throwable $e) {
        $this->logger->error('Failed to create MadelineProto instance', [
            'domain' => $domain,
            'session_id' => $sessionId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        throw $e; // Пробрасываем ошибку дальше
    }
}

    public function updateSessionName(string $domain, string $sessionId, string $sessionName): bool
    {
        return $this->tokenRepository->updateSessionName($domain, $sessionId, $sessionName);
    }

    private function extractTelegramId(string $chatId): string
    {
        if (strpos($chatId, 'tguser_') === 0) {
            return substr($chatId, 7);
        }
        return $chatId;
    }
}