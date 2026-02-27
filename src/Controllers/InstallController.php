<?php

declare(strict_types=1);

namespace BitrixTelegram\Controllers;

use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

class InstallController
{
    private BitrixService $bitrixService;
    private TokenRepository $tokenRepository;
    private Logger $logger;
    private array $config;
    private \PDO $pdo;

    public function __construct(
        BitrixService $bitrixService,
        TokenRepository $tokenRepository,
        Logger $logger,
        array $config,
        \PDO $pdo
    ) {
        $this->bitrixService   = $bitrixService;
        $this->tokenRepository = $tokenRepository;
        $this->logger          = $logger;
        $this->config          = $config;
        $this->pdo             = $pdo;
    }

    public function install(array $data): void
    {
        $this->logger->info('Installing application');

        try {
            $this->logger->info('Received installation data', ['raw_data_keys' => array_keys($data)]);

            if (empty($data['auth'])) {
                throw new \Exception('Missing required field: auth');
            }
            $auth = $data['auth'];

            foreach (['member_id', 'domain', 'refresh_token', 'access_token'] as $field) {
                if (empty($auth[$field])) {
                    throw new \Exception("Missing required field: {$field} in auth array");
                }
            }

            $expires = $auth['expires'] ?? (time() + ($auth['expires_in'] ?? 3600));

            $installData = [
                'domain'          => $auth['domain'],
                'member_id'       => $auth['member_id'],
                'refresh_token'   => $auth['refresh_token'],
                'access_token'    => $auth['access_token'],
                'client_id'       => $this->config['bitrix']['client_id'],
                'client_secret'   => $this->config['bitrix']['client_secret'],
                'client_endpoint' => $auth['client_endpoint'] ?? ('https://' . $auth['domain'] . '/rest/'),
                'expires'         => $expires,
            ];

            if (!$this->tokenRepository->saveInstallData($installData)) {
                throw new \Exception('Failed to save installation data');
            }

            $connectorId = $this->tokenRepository->getConnectorId($auth['domain'], 'max')
                        ?: 'max_' . $auth['member_id'];

            $handlerUrl = $this->config['app']['url'] . '/webhook.php';
            $this->bitrixService->registerConnector($connectorId, $handlerUrl);
            $this->bitrixService->bindEvent('OnImConnectorMessageAdd', $handlerUrl, $auth['domain']);

            $this->renderInstallSuccess();
        } catch (\Exception $e) {
            $this->logger->logException($e, 'Installation failed');
            $this->renderInstallError($e->getMessage());
        }
    }

    /**
     * Активация коннектора.
     * $apiToken — токен из личного кабинета ConnectHub (таблица users.api_token).
     */
    public function activate(array $data, string $apiToken): void
    {
        $domain  = $data['DOMAIN'] ?? $data['auth']['domain'] ?? '';
        $options = json_decode($data['PLACEMENT_OPTIONS'] ?? '{}', true) ?? [];

        $this->logger->info('Activating connector', [
            'domain'  => $domain,
            'options' => $options,
        ]);

        // 1. Валидируем токен — ищем user_id в таблице users
        $userId = $this->getUserIdByApiToken($apiToken);
        if ($userId === null) {
            $this->renderActivateError('Неверный API-токен. Проверьте токен в личном кабинете ConnectHub.');
            return;
        }

        // 2. Получаем connector_id для домена
        $connectorId = $this->tokenRepository->getConnectorId($domain, 'max');
        if (empty($connectorId)) {
            $this->renderActivateError("Домен {$domain} не найден в системе. Сначала установите приложение.");
            return;
        }

        // 3. Активируем коннектор в Bitrix24
        $result = $this->bitrixService->activateConnector(
            $connectorId,
            (int) ($options['LINE'] ?? 0),
            (bool) ($options['ACTIVE_STATUS'] ?? true),
            $domain
        );

        if (empty($result['result'])) {
            $error = $result['error_description'] ?? $result['error'] ?? print_r($result, true);
            $this->renderActivateError("Ошибка Bitrix24: {$error}");
            return;
        }

        $lineId = (int) ($options['LINE'] ?? 0);

        // 4. Обновляем line в tokenRepository
        $this->tokenRepository->updateLine($connectorId, $lineId);

        // 5. Сохраняем user_id в bitrix_integration_tokens
        $this->saveUserIdToToken($domain, $connectorId, $userId);

        $this->logger->info('Connector activated', [
            'connector_id' => $connectorId,
            'line_id'      => $lineId,
            'user_id'      => $userId,
            'domain'       => $domain,
        ]);

        $this->renderActivateSuccess($connectorId, $lineId, $userId);
    }

    public function uninstall(array $data): void
    {
        $this->logger->info('Uninstalling application', ['domain' => $data['auth']['domain'] ?? '']);

        $domain      = $data['auth']['domain'];
        $connectorId = $this->tokenRepository->getConnectorId($domain, 'max');
        $this->bitrixService->unregisterConnector($connectorId, $domain);

        echo json_encode(['status' => 'success', 'message' => 'Application uninstalled']);
    }

    // ──────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Найти user_id по api_token из таблицы users.
     */
    private function getUserIdByApiToken(string $apiToken): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM users WHERE api_token = ? LIMIT 1'
            );
            $stmt->execute([trim($apiToken)]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? (int) $row['id'] : null;
        } catch (\Throwable $e) {
            $this->logger->logException($e, 'getUserIdByApiToken failed');
            return null;
        }
    }

    /**
     * Записать user_id в таблицу bitrix_integration_tokens.
     * Обновляем по domain + connector_id.
     */
    private function saveUserIdToToken(string $domain, string $connectorId, int $userId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE bitrix_integration_tokens
                    SET user_id = ?
                  WHERE domain = ?
                    AND connector_id = ?'
            );
            $stmt->execute([$userId, $domain, $connectorId]);

            $affected = $stmt->rowCount();
            $this->logger->info('user_id saved to bitrix_integration_tokens', [
                'user_id'      => $userId,
                'domain'       => $domain,
                'connector_id' => $connectorId,
                'rows_updated' => $affected,
            ]);

            if ($affected === 0) {
                $this->logger->warning('No rows updated — check domain/connector_id match', [
                    'domain'       => $domain,
                    'connector_id' => $connectorId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->logException($e, 'saveUserIdToToken failed');
        }
    }

    // ──────────────────────────────────────────────────────────
    //  Render methods
    // ──────────────────────────────────────────────────────────

    private function renderInstallSuccess(): void
    {
        echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка завершена</title>
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f5f7fa; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,.1); padding: 36px; width: 400px; text-align: center; }
        .ico { font-size: 48px; margin-bottom: 18px; color: #16a34a; }
        h1 { font-size: 22px; margin-bottom: 12px; color: #18181b; }
        p { font-size: 14px; margin-bottom: 22px; line-height: 1.6; color: #52525b; }
        .btn { background: #2563eb; color: #fff; border: none; padding: 11px 24px; border-radius: 6px; font-size: 15px; cursor: pointer; font-weight: 600; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
<div class="card">
    <div class="ico">✓</div>
    <h1>Установка завершена</h1>
    <p>Приложение успешно установлено в ваш Bitrix24.</p>
    <button id="continueBtn" class="btn">Продолжить</button>
</div>
<script>
BX24.init(() => {
    document.getElementById('continueBtn').addEventListener('click', () => { BX24.installFinish(); });
});
</script>
</body>
</html>
HTML;
    }

    private function renderInstallError(string $error): void
    {
        echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Ошибка установки</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 8px; padding: 32px; width: 420px; text-align: center; }
        .ico { font-size: 48px; margin-bottom: 18px; color: #dc2626; }
        h1 { font-size: 22px; margin-bottom: 14px; color: #18181b; }
        .err { color: #dc2626; padding: 12px; background: #fef2f2; border-radius: 6px; font-size: 13px; }
    </style>
</head>
<body>
<div class="card">
    <div class="ico">✗</div>
    <h1>Ошибка установки</h1>
    <p style="color:#52525b;font-size:14px;margin-bottom:16px">При установке приложения произошла ошибка.</p>
    <div class="err">{$error}</div>
</div>
</body>
</html>
HTML;
    }

    private function renderActivateSuccess(string $connectorId, int $lineId, int $userId): void
    {
        echo <<<HTML
<style>
    .success-card {
        max-width: 500px; margin: 24px auto; padding: 22px 24px;
        border-radius: 12px; background: #f0fdf4;
        box-shadow: 0 4px 14px rgba(22,163,74,.12);
        border-left: 6px solid #16a34a;
        font-family: "Segoe UI", system-ui, sans-serif;
    }
    .success-card h3 { margin: 0 0 14px; color: #15803d; display: flex; align-items: center; gap: 8px; font-size: 17px; }
    .row { margin: 5px 0; font-size: 13.5px; color: #374151; }
    .row strong { color: #111827; }
    .note { margin-top: 14px; font-size: 12.5px; color: #6b7280; }
</style>
<div class="success-card">
    <h3>✅ Коннектор успешно подключён</h3>
    <div class="row"><strong>Открытая линия:</strong> #{$lineId}</div>
    <div class="row"><strong>Коннектор:</strong> {$connectorId}</div>
    <div class="row"><strong>Пользователь ConnectHub:</strong> #{$userId}</div>
    <div class="note">💡 Все профили этого аккаунта будут получать сообщения через данный коннектор.</div>
</div>
HTML;
    }

    private function renderActivateError(string $message): void
    {
        echo <<<HTML
<style>
    .err-card {
        max-width: 500px; margin: 24px auto; padding: 22px 24px;
        border-radius: 12px; background: #fef2f2;
        box-shadow: 0 4px 14px rgba(220,38,38,.10);
        border-left: 6px solid #dc2626;
        font-family: "Segoe UI", system-ui, sans-serif;
    }
    .err-card h3 { margin: 0 0 12px; color: #b91c1c; display: flex; align-items: center; gap: 8px; font-size: 17px; }
    .err-msg { font-size: 13.5px; color: #374151; }
</style>
<div class="err-card">
    <h3>❌ Ошибка активации</h3>
    <div class="err-msg">{$message}</div>
</div>
HTML;
    }
}