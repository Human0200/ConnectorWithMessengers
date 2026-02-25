<?php

declare(strict_types=1);

namespace BitrixTelegram\Controllers;

use BitrixTelegram\Repositories\ApiTokenRepository;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Middleware\ApiTokenMiddleware;
use BitrixTelegram\Helpers\Logger;

/**
 * REST-контроллер для управления API-токенами.
 *
 * Маршруты (обрабатываются в api.php):
 *   GET    /api/tokens               → список всех токенов
 *   POST   /api/tokens               → создать токен
 *   DELETE /api/tokens/{id}          → отозвать/удалить токен
 *   PATCH  /api/tokens/{id}/domain   → привязать домен
 *   GET    /api/tokens/stats         → статистика
 *   GET    /api/domains              → список доменов Bitrix24
 *   POST   /api/domains/{domain}/validate → проверить связь с Bitrix24
 */
class ApiController
{
    public function __construct(
        private ApiTokenRepository $apiTokenRepository,
        private TokenRepository    $tokenRepository,
        private ApiTokenMiddleware $middleware,
        private Logger             $logger
    ) {}

    // ─── Routing ────────────────────────────────────────────────────────────

    public function handle(string $method, string $path): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, X-Api-Token, Content-Type');

        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Сегменты пути: /api/tokens → ['tokens']
        $segments = array_values(array_filter(explode('/', ltrim($path, '/')), 'strlen'));
        // Убираем префикс 'api'
        if (isset($segments[0]) && $segments[0] === 'api') {
            array_shift($segments);
        }

        $resource = $segments[0] ?? '';
        $id       = isset($segments[1]) && is_numeric($segments[1]) ? (int) $segments[1] : null;
        $action   = $segments[2] ?? null;

        // ── Публичный маршрут: POST /api/setup (первоначальная настройка) ──
        if ($resource === 'setup' && $method === 'POST') {
            $this->handleSetup();
            return;
        }

        // ── Все остальные маршруты требуют токен ────────────────────────────
        $tokenRecord = $this->middleware->authenticate();
        if (!$tokenRecord) {
            $this->middleware->unauthorized('Provide a valid API token via Authorization: Bearer <token>');
        }

        // ── Маршруты ────────────────────────────────────────────────────────
        match (true) {
            $resource === 'tokens' && $method === 'GET'  && $id === null && $action === 'stats'
                => $this->getStats($tokenRecord),

            $resource === 'tokens' && $method === 'GET'  && $id === null
                => $this->listTokens($tokenRecord),

            $resource === 'tokens' && $method === 'POST' && $id === null
                => $this->createToken($tokenRecord),

            $resource === 'tokens' && $method === 'DELETE' && $id !== null
                => $this->deleteToken($tokenRecord, $id),

            $resource === 'tokens' && $method === 'PATCH'  && $id !== null && $action === 'domain'
                => $this->linkDomain($tokenRecord, $id),

            $resource === 'tokens' && $method === 'PATCH'  && $id !== null && $action === 'revoke'
                => $this->revokeToken($tokenRecord, $id),

            $resource === 'domains' && $method === 'GET'
                => $this->listDomains($tokenRecord),

            $resource === 'domains' && $method === 'POST' && $action === 'validate'
                => $this->validateDomain($tokenRecord, $segments[1] ?? ''),

            default => $this->notFound(),
        };
    }

    // ─── Handlers ───────────────────────────────────────────────────────────

    /**
     * Первоначальная настройка: создать первый admin-токен.
     * Работает только если токенов ещё нет.
     */
    private function handleSetup(): void
    {
        $stats = $this->apiTokenRepository->getStats();

        if ((int)($stats['total'] ?? 0) > 0) {
            $this->json(['error' => 'Setup already completed. Use an existing admin token.'], 403);
            return;
        }

        $body = $this->parseBody();
        $name = trim($body['name'] ?? 'Admin Token');

        if (empty($name)) {
            $this->json(['error' => 'Field "name" is required'], 422);
            return;
        }

        $record = $this->apiTokenRepository->create($name, null, ['admin', 'read', 'write']);

        $this->logger->info('Initial admin token created', ['name' => $name]);

        // Показываем токен только один раз!
        $this->json([
            'message' => '✅ Setup complete! Save this token — it will never be shown again.',
            'token'   => $record['token'],
            'id'      => $record['id'],
            'name'    => $record['name'],
            'scopes'  => $record['scopes'],
        ], 201);
    }

    private function listTokens(array $caller): void
    {
        if (!$this->middleware->hasScope($caller, 'read')) {
            $this->middleware->forbidden();
        }

        $tokens = $this->apiTokenRepository->getAll();
        $this->json(['data' => $tokens, 'total' => count($tokens)]);
    }

    private function createToken(array $caller): void
    {
        if (!$this->middleware->hasScope($caller, 'admin')) {
            $this->middleware->forbidden('Only admin tokens can create new tokens');
        }

        $body = $this->parseBody();

        $name      = trim($body['name'] ?? '');
        $domain    = trim($body['domain'] ?? '') ?: null;
        $scopes    = $body['scopes'] ?? ['read', 'write'];
        $expiresIn = isset($body['expires_in_days']) ? (int)$body['expires_in_days'] : null;

        if (empty($name)) {
            $this->json(['error' => 'Field "name" is required'], 422);
            return;
        }

        if (!is_array($scopes)) {
            $this->json(['error' => 'Field "scopes" must be an array'], 422);
            return;
        }

        $allowed = ['read', 'write', 'admin'];
        $invalid = array_diff($scopes, $allowed);
        if ($invalid) {
            $this->json(['error' => 'Invalid scopes: ' . implode(', ', $invalid) . '. Allowed: ' . implode(', ', $allowed)], 422);
            return;
        }

        $expiresAt = $expiresIn ? (new \DateTime("+{$expiresIn} days")) : null;

        $record = $this->apiTokenRepository->create($name, $domain, $scopes, $expiresAt);

        $this->logger->info('API token created', [
            'name'       => $name,
            'domain'     => $domain,
            'created_by' => $caller['id'],
        ]);

        // Возвращаем полный токен только при создании!
        $this->json([
            'message' => '✅ Token created. Save it — it will not be shown again.',
            'token'   => $record['token'],
            'id'      => $record['id'],
            'name'    => $record['name'],
            'domain'  => $record['domain'],
            'scopes'  => $record['scopes'],
            'expires_at' => $record['expires_at'],
        ], 201);
    }

    private function revokeToken(array $caller, int $id): void
    {
        if (!$this->middleware->hasScope($caller, 'admin')) {
            $this->middleware->forbidden();
        }

        $ok = $this->apiTokenRepository->revoke($id);
        $this->json(['success' => $ok, 'id' => $id]);
    }

    private function deleteToken(array $caller, int $id): void
    {
        if (!$this->middleware->hasScope($caller, 'admin')) {
            $this->middleware->forbidden();
        }

        $ok = $this->apiTokenRepository->delete($id);
        $this->json(['success' => $ok, 'id' => $id]);
    }

    private function linkDomain(array $caller, int $id): void
    {
        if (!$this->middleware->hasScope($caller, 'write')) {
            $this->middleware->forbidden();
        }

        $body   = $this->parseBody();
        $domain = trim($body['domain'] ?? '');

        if (empty($domain)) {
            $this->json(['error' => 'Field "domain" is required'], 422);
            return;
        }

        // Проверяем, что домен существует в bitrix_integration_tokens
        $bitrixData = $this->tokenRepository->findByDomain($domain);
        if (!$bitrixData) {
            $this->json(['error' => "Domain '$domain' not found in Bitrix integration table. Install the app first."], 404);
            return;
        }

        $ok = $this->apiTokenRepository->linkDomain($id, $domain);
        $this->json(['success' => $ok, 'id' => $id, 'domain' => $domain]);
    }

    private function listDomains(array $caller): void
    {
        if (!$this->middleware->hasScope($caller, 'read')) {
            $this->middleware->forbidden();
        }

        // Получаем все домены из bitrix_integration_tokens
        $stmt = $this->tokenRepository->getAllDomains();
        $this->json(['data' => $stmt]);
    }

    private function validateDomain(array $caller, string $domain): void
    {
        if (!$this->middleware->hasScope($caller, 'read')) {
            $this->middleware->forbidden();
        }

        $domain = trim(urldecode($domain));
        $data   = $this->tokenRepository->findByDomain($domain);

        if (!$data) {
            $this->json(['valid' => false, 'domain' => $domain, 'error' => 'Domain not found']);
            return;
        }

        $this->json([
            'valid'      => true,
            'domain'     => $domain,
            'member_id'  => $data['member_id'] ?? null,
            'has_max_token' => !empty($data['api_token_max']),
            'has_telegram_token' => !empty($data['telegram_bot_token']),
            'token_expires' => $data['token_expires'] ?? null,
            'connector_id' => $data['connector_id'] ?? null,
        ]);
    }

    private function getStats(array $caller): void
    {
        $stats = $this->apiTokenRepository->getStats();
        $this->json($stats);
    }

    private function notFound(): void
    {
        $this->json(['error' => 'Route not found'], 404);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return $_POST;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $_POST;
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}