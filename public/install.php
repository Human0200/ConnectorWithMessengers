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
    $logger->info('Запрос установки приложения/install.php called');
    $installController = new InstallController(
        $bitrixService,
        $tokenRepository,
        $logger,
        $config
    );

    // Проверяем тип запроса
    if (!empty($_REQUEST['event']) && $_REQUEST['event'] === 'ONAPPINSTALL' || !empty($_REQUEST['event']) && $_REQUEST['event'] === 'ONAPPUPDATE') {
        // Обработка события установки
        $installController->install($_REQUEST);
        
    } elseif (!empty($_REQUEST['PLACEMENT']) && $_REQUEST['PLACEMENT'] === 'DEFAULT') {
        // Обработка установки через плейсмент
        $installController->install($_REQUEST);
    } else {
        // Показываем информацию о приложении
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Установка приложения</title>
            <script src="//api.bitrix24.com/api/v1/"></script>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f5f7fa;
                    margin: 0;
                    padding: 0;
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
                .logo {
                    text-align: center;
                    margin-bottom: 30px;
                }
                .logo-icon {
                    font-size: 64px;
                    margin-bottom: 10px;
                }
                h1 {
                    color: #333;
                    text-align: center;
                    margin-bottom: 10px;
                }
                .version {
                    text-align: center;
                    color: #999;
                    margin-bottom: 30px;
                    font-size: 14px;
                }
                .features {
                    margin: 30px 0;
                }
                .feature {
                    display: flex;
                    align-items: flex-start;
                    margin-bottom: 15px;
                    padding: 15px;
                    background: #f8f9fa;
                    border-radius: 8px;
                }
                .feature-icon {
                    font-size: 24px;
                    margin-right: 15px;
                }
                .feature-text h3 {
                    margin: 0 0 5px 0;
                    color: #333;
                    font-size: 16px;
                }
                .feature-text p {
                    margin: 0;
                    color: #666;
                    font-size: 14px;
                    line-height: 1.5;
                }
                .info {
                    background: #e3f2fd;
                    border-left: 4px solid #2196f3;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .info p {
                    margin: 0;
                    color: #1976d2;
                    font-size: 14px;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="logo">
                    <div class="logo-icon">💬</div>
                    <h1>Bitrix Multi-Messenger Integration</h1>
                    <div class="version">Версия 1.0.0</div>
                </div>

                <div class="features">
                    <div class="feature">
                        <div class="feature-icon">📱</div>
                        <div class="feature-text">
                            <h3>Поддержка нескольких мессенджеров</h3>
                            <p>Интеграция с Telegram и Max в одном приложении</p>
                        </div>
                    </div>

                    <div class="feature">
                        <div class="feature-icon">🔄</div>
                        <div class="feature-text">
                            <h3>Двусторонняя синхронизация</h3>
                            <p>Получайте и отправляйте сообщения между Bitrix24 и мессенджерами</p>
                        </div>
                    </div>

                    <div class="feature">
                        <div class="feature-icon">📎</div>
                        <div class="feature-text">
                            <h3>Поддержка медиа</h3>
                            <p>Обработка фото, документов, голосовых сообщений и видео</p>
                        </div>
                    </div>

                    <div class="feature">
                        <div class="feature-icon">🤖</div>
                        <div class="feature-text">
                            <h3>Автоопределение источника</h3>
                            <p>Автоматическое определение типа мессенджера по структуре сообщения</p>
                        </div>
                    </div>
                </div>

                <div class="info">
                    <p><strong>ℹ️ Информация:</strong> Приложение готово к установке. Установите его через маркетплейс Bitrix24 или используйте локальное приложение.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
} catch (\Exception $e) {
    $logger->logException($e, 'Installation failed');
    http_response_code(500);
    echo '<h1>Ошибка установки</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}