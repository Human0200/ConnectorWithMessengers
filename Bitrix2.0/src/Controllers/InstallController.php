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

    public function __construct(
        BitrixService $bitrixService,
        TokenRepository $tokenRepository,
        Logger $logger,
        array $config
    ) {
        $this->bitrixService = $bitrixService;
        $this->tokenRepository = $tokenRepository;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function install(array $data): void
    {
        $this->logger->info('Installing application', ['domain' => $data['auth']['domain'] ?? $data['DOMAIN']]);

        try {
            // Сохраняем данные установки
            $installData = [
                'domain' => $data['DOMAIN'] ?? $data['auth']['domain'],
                'member_id' => $data['member_id'],
                'refresh_token' => $data['auth']['refresh_token'],
                'access_token' => $data['auth']['access_token'],
                'client_id' => $this->config['bitrix']['client_id'],
                'client_secret' => $this->config['bitrix']['client_secret'],
                'expires' => $data['AUTH_EXPIRES'],
            ];

            $this->tokenRepository->saveInstallData($installData);

            // Получаем connector_id
            $connectorId = $this->tokenRepository->getConnectorId($data['auth']['domain'],  'max'); //недоделано

            // Регистрируем коннектор
            $handlerUrl = $this->config['app']['url'] . '/webhook.php';
            $this->bitrixService->registerConnector($connectorId, $handlerUrl);

            // Привязываем событие
            $this->bitrixService->bindEvent(
                'OnImConnectorMessageAdd',
                $handlerUrl,
                $data['auth']['domain']
            );

            $this->renderInstallSuccess();
        } catch (\Exception $e) {
            $this->logger->logException($e, 'Installation failed');
            $this->renderInstallError($e->getMessage());
        }
    }

    public function activate(array $data): void
    {
        $options = json_decode($data['PLACEMENT_OPTIONS'], true);
        $domain = $data['DOMAIN'] ?? $data['auth']['domain'] ?? '';
        $connectorId = $this->tokenRepository->getConnectorId($domain, 'max'); //не доделано

        $result = $this->bitrixService->activateConnector(
            $connectorId,
            (int) $options['LINE'],
            (bool) $options['ACTIVE_STATUS'],
            $domain
        );

        if (!empty($result['result'])) {
            $this->tokenRepository->updateLine($connectorId, (int) $options['LINE']);
            $this->renderActivateSuccess($connectorId, (int) $options['LINE']);
        } else {
            echo 'Ошибка активации: ' . print_r($result, true);
        }
    }

    public function uninstall(array $data): void
    {
        $this->logger->info('Uninstalling application', ['domain' => $data['auth']['domain'] ?? '']);

        $domain = $data['auth']['domain'];
        $connectorId = $this->tokenRepository->getConnectorId($domain, 'max'); //недоделано

        $this->bitrixService->unregisterConnector($connectorId, $domain);

        echo json_encode([
            'status' => 'success',
            'message' => 'Application uninstalled',
        ]);
    }

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
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 30px;
            width: 400px;
            text-align: center;
        }
        .icon { font-size: 48px; margin-bottom: 20px; color: #2fc06e; }
        h1 { font-size: 24px; margin-bottom: 15px; color: #424956; }
        p { font-size: 16px; margin-bottom: 25px; line-height: 1.5; }
        .btn {
            background-color: #2f81b7;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn:hover { background-color: #236a9a; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">✓</div>
        <h1>Установка завершена</h1>
        <p>Приложение успешно установлено в ваш Bitrix24.</p>
        <button id="continueBtn" class="btn">Продолжить</button>
    </div>
    <script>
        BX24.init(() => {
            document.getElementById('continueBtn').addEventListener('click', () => {
                BX24.installFinish();
            });
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
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f5f7fa;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            width: 400px;
            text-align: center;
        }
        .icon { font-size: 48px; margin-bottom: 20px; color: #ff5752; }
        h1 { font-size: 24px; margin-bottom: 15px; color: #424956; }
        .error { color: #ff5752; margin-top: 15px; padding: 10px; background: #fff5f5; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">✗</div>
        <h1>Ошибка установки</h1>
        <p>При установке приложения произошла ошибка.</p>
        <div class="error">{$error}</div>
    </div>
</body>
</html>
HTML;
    }

    private function renderActivateSuccess(string $connectorId, int $lineId): void
    {
        echo <<<HTML
<style>
    .success-card {
        max-width: 500px;
        margin: 20px auto;
        padding: 20px;
        border-radius: 12px;
        background: #f8f9ff;
        box-shadow: 0 4px 12px rgba(9, 82, 201, 0.15);
        border-left: 6px solid #0952C9;
        font-family: "Segoe UI", Arial, sans-serif;
    }
    .success-card h3 {
        margin: 0 0 15px 0;
        color: #0952C9;
        display: flex;
        align-items: center;
        gap: 8px;
    }
</style>
<div class="success-card">
    <h3><span>✅</span> Успешно!</h3>
    <div><strong>ID LINE:</strong> {$lineId}</div>
    <div><strong>CONNECTOR:</strong> {$connectorId}</div>
    <div style="margin-top: 15px; font-size: 0.9em; color: #555;">
        💡 Подключение активно и готово к использованию.
    </div>
</div>
HTML;
    }
}