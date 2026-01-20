<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Services\BitrixService;
use BitrixTelegram\Services\TokenService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Controllers\InstallController;

// Загружаем конфигурацию
$config = require __DIR__ . '/../config/config.php';

// Инициализируем зависимости
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();

$logger = new Logger($config['logging']);
$tokenRepository = new TokenRepository($pdo);
$tokenService = new TokenService($tokenRepository, $logger, $config['bitrix']);
$bitrixService = new BitrixService($tokenRepository, $tokenService, $logger);

try {
    $installController = new InstallController(
        $bitrixService,
        $tokenRepository,
        $logger,
        $config
    );

    // Проверяем наличие данных для активации
    if (!empty($_REQUEST['PLACEMENT']) && $_REQUEST['PLACEMENT'] === 'SETTING_CONNECTOR') {
        $installController->activate($_REQUEST);
    } else {
        // Показываем форму активации
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Активация коннектора</title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f5f7fa;
                    margin: 0;
                    padding: 20px;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                }
                
                .container {
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                    padding: 40px;
                    max-width: 600px;
                    width: 100%;
                }
                
                h1 {
                    color: #333;
                    margin-bottom: 10px;
                }
                
                .subtitle {
                    color: #666;
                    margin-bottom: 30px;
                }
                
                .info {
                    background: #e3f2fd;
                    border-left: 4px solid #2196f3;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                
                .warning {
                    background: #fff3e0;
                    border-left: 4px solid #ff9800;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                
                ol {
                    margin: 15px 0;
                    padding-left: 20px;
                }
                
                li {
                    margin: 10px 0;
                    line-height: 1.6;
                }
                
                code {
                    background: #f5f5f5;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-family: 'Courier New', monospace;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Активация коннектора</h1>
                <p class="subtitle">Настройка интеграции с открытыми линиями Bitrix24</p>
                
                <div class="info">
                    <strong>ℹ️ Инструкция по активации:</strong>
                    <ol>
                        <li>Перейдите в Bitrix24 → CRM → Открытые линии</li>
                        <li>Выберите открытую линию или создайте новую</li>
                        <li>Нажмите "Подключить мессенджер"</li>
                        <li>Найдите в списке "Telegram Integration" или "Max Integration"</li>
                        <li>Выполните настройку согласно инструкциям</li>
                    </ol>
                </div>
                
                <div class="warning">
                    <strong>⚠️ Важно:</strong>
                    <p>Эта страница вызывается автоматически из Bitrix24 при настройке коннектора. Для ручной активации используйте интерфейс Bitrix24.</p>
                </div>
                
                <div class="info">
                    <strong>📝 Параметры активации:</strong>
                    <ul>
                        <li><code>PLACEMENT</code> - должен быть <code>SETTING_CONNECTOR</code></li>
                        <li><code>PLACEMENT_OPTIONS</code> - JSON с параметрами линии</li>
                        <li><code>DOMAIN</code> - домен Bitrix24</li>
                    </ul>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
} catch (\Exception $e) {
    $logger->logException($e, 'Activation failed');
    http_response_code(500);
    
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ошибка активации</title>
        <style>
            body {
                font-family: 'Segoe UI', sans-serif;
                background-color: #f5f7fa;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
            }
            .error-container {
                background: white;
                border-radius: 12px;
                padding: 40px;
                max-width: 500px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            }
            .error-icon {
                font-size: 48px;
                text-align: center;
                margin-bottom: 20px;
            }
            h1 {
                color: #f44336;
                text-align: center;
                margin-bottom: 20px;
            }
            .error-message {
                background: #ffebee;
                border-left: 4px solid #f44336;
                padding: 15px;
                border-radius: 4px;
                margin: 20px 0;
            }
            .error-details {
                background: #f5f5f5;
                padding: 15px;
                border-radius: 4px;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                overflow-x: auto;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">❌</div>
            <h1>Ошибка активации</h1>
            <div class="error-message">
                <strong>Произошла ошибка при активации коннектора:</strong>
            </div>
            <div class="error-details">
                <?php echo htmlspecialchars($e->getMessage()); ?>
            </div>
            <?php if ($config['app']['debug']): ?>
            <div class="error-details" style="margin-top: 15px;">
                <strong>Файл:</strong> <?php echo htmlspecialchars($e->getFile()); ?><br>
                <strong>Строка:</strong> <?php echo $e->getLine(); ?>
            </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}