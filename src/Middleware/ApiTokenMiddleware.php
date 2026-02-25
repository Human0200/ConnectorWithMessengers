<?php

declare(strict_types=1);

namespace BitrixTelegram\Middleware;

use BitrixTelegram\Repositories\ApiTokenRepository;
use BitrixTelegram\Helpers\Logger;

class ApiTokenMiddleware
{
    public function __construct(
        private ApiTokenRepository $apiTokenRepository,
        private Logger $logger
    ) {}

    /**
     * Извлечь и валидировать токен из запроса.
     * Поддерживает:
     *   - Authorization: Bearer <token>
     *   - X-Api-Token: <token>
     *   - ?api_token=<token>  (только GET, для удобства тестирования)
     *
     * @return array|null  Запись токена из БД или null если не валиден
     */
    public function authenticate(): ?array
    {
        $raw = $this->extractRawToken();

        if (!$raw) {
            return null;
        }

        $record = $this->apiTokenRepository->validate($raw);

        if (!$record) {
            $this->logger->warning('Invalid or expired API token attempt', [
                'token_preview' => substr($raw, 0, 12) . '...',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            return null;
        }

        $this->logger->debug('API token authenticated', [
            'token_id' => $record['id'],
            'name'     => $record['name'],
            'domain'   => $record['domain'],
        ]);

        return $record;
    }

    /**
     * Проверить наличие нужного scope в токене
     */
    public function hasScope(array $tokenRecord, string $scope): bool
    {
        $scopes = $tokenRecord['scopes'] ?? [];
        return in_array($scope, $scopes, true) || in_array('admin', $scopes, true);
    }

    /**
     * Ответить 401 и завершить выполнение
     */
    public function unauthorized(string $message = 'Unauthorized'): void
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message, 'code' => 401]);
        exit;
    }

    /**
     * Ответить 403 и завершить выполнение
     */
    public function forbidden(string $message = 'Forbidden'): void
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message, 'code' => 403]);
        exit;
    }

    // ─── private ────────────────────────────────────────────────────────────

    private function extractRawToken(): ?string
    {
        // 1. Authorization: Bearer <token>
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? apache_request_headers()['Authorization']
            ?? null;

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return trim(substr($authHeader, 7));
        }

        // 2. X-Api-Token header
        $customHeader = $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if ($customHeader) {
            return trim($customHeader);
        }

        // 3. Query param (только для GET — не для мутирующих запросов)
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['api_token'])) {
            return trim($_GET['api_token']);
        }

        return null;
    }
}