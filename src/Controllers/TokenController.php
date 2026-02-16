<?php

declare(strict_types=1);

namespace BitrixTelegram\Controllers;

use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

class TokenController
{
    private TokenRepository $tokenRepository;
    private Logger $logger;

    public function __construct(
        TokenRepository $tokenRepository,
        Logger $logger
    ) {
        $this->tokenRepository = $tokenRepository;
        $this->logger = $logger;
    }

    /**
     * Добавление/обновление токена Max
     */
    public function addMaxToken(array $data): array
    {
        $this->logger->info('Adding Max token', ['domain' => $data['domain'] ?? '']);

        try {
            // Валидация
            if (empty($data['domain'])) {
                throw new \Exception('Параметр domain обязателен');
            }

            if (empty($data['api_token_max'])) {
                throw new \Exception('Параметр api_token_max обязателен');
            }

            $domain = trim($data['domain']);
            $apiTokenMax = trim($data['api_token_max']);

            // Сохранение токена
            $success = $this->tokenRepository->saveMaxToken($domain, $apiTokenMax);

            if ($success) {
                // Проверяем, создана ли запись или обновлена
                $tokenData = $this->tokenRepository->findByDomain($domain);
                $action = !empty($tokenData['date_created']) ? 'updated' : 'created';

                $this->logger->info('Max token saved', [
                    'domain' => $domain,
                    'action' => $action,
                ]);

                return [
                    'success' => true,
                    'action' => $action,
                    'domain' => $domain,
                ];
            }

            throw new \Exception('Не удалось сохранить токен');

        } catch (\Exception $e) {
            $this->logger->logException($e, 'Failed to add Max token');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получение токена по домену
     */
    public function getToken(string $domain): ?array
    {
        $this->logger->debug('Getting token', ['domain' => $domain]);

        try {
            $tokenData = $this->tokenRepository->findByDomain($domain);

            if (!$tokenData) {
                return [
                    'success' => false,
                    'error' => 'Токен не найден',
                ];
            }

            // Удаляем чувствительные данные для вывода
            unset($tokenData['refresh_token']);
            unset($tokenData['access_token']);
            unset($tokenData['client_secret']);

            return [
                'success' => true,
                'data' => $tokenData,
            ];

        } catch (\Exception $e) {
            $this->logger->logException($e, 'Failed to get token');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получение connector_id по домену
     */
    public function getConnectorId(string $domain): array
    {
        $this->logger->debug('Getting connector ID', ['domain' => $domain]);

        try {
            $connectorId = $this->tokenRepository->getConnectorId($domain);

            if (!$connectorId) {
                return [
                    'success' => false,
                    'error' => 'Connector ID не найден',
                ];
            }

            return [
                'success' => true,
                'connector_id' => $connectorId,
                'domain' => $domain,
            ];

        } catch (\Exception $e) {
            $this->logger->logException($e, 'Failed to get connector ID');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Обновление ID открытой линии
     */
    public function updateLine(string $connectorId, int $lineId): array
    {
        $this->logger->info('Updating line', [
            'connector_id' => $connectorId,
            'line_id' => $lineId,
        ]);

        try {
            $success = $this->tokenRepository->updateLine($connectorId, $lineId);

            if ($success) {
                return [
                    'success' => true,
                    'connector_id' => $connectorId,
                    'line_id' => $lineId,
                ];
            }

            throw new \Exception('Не удалось обновить ID линии');

        } catch (\Exception $e) {
            $this->logger->logException($e, 'Failed to update line');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получение информации о линии по connector_id
     */
    public function getLine(string $connectorId): array
    {
        $this->logger->debug('Getting line', ['connector_id' => $connectorId]);

        try {
            $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);

            if (!$lineId) {
                return [
                    'success' => false,
                    'error' => 'Линия не найдена',
                ];
            }

            return [
                'success' => true,
                'connector_id' => $connectorId,
                'line_id' => $lineId,
            ];

        } catch (\Exception $e) {
            $this->logger->logException($e, 'Failed to get line');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Проверка истечения токена
     */
    public function checkTokenExpiration(string $domain): array
    {
        $this->logger->debug('Checking token expiration', ['domain' => $domain]);

        try {
            $tokenData = $this->tokenRepository->findByDomain($domain);

            if (!$tokenData) {
                return [
                    'success' => false,
                    'error' => 'Токен не найден',
                ];
            }

            $expiresAt = (int) ($tokenData['token_expires'] ?? 0);
            $now = time();
            $isExpired = $expiresAt < $now;
            $expiresIn = $expiresAt - $now;

            return [
                'success' => true,
                'domain' => $domain,
                'is_expired' => $isExpired,
                'expires_at' => $expiresAt,
                'expires_in' => $expiresIn,
                'expires_at_formatted' => date('Y-m-d H:i:s', $expiresAt),
            ];

        } catch (\Exception $e) {
            $this->logger->logException($e, 'Failed to check token expiration');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Список всех доменов с токенами
     */
    public function listDomains(): array
    {
        $this->logger->debug('Listing all domains');

        try {
            // Этот метод нужно добавить в TokenRepository
            // Для примера возвращаем заглушку
            return [
                'success' => true,
                'domains' => [],
                'total' => 0,
            ];

        } catch (\Exception $e) {
            $this->logger->logException($e, 'Failed to list domains');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}