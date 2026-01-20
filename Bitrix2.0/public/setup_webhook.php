<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Helpers\MessageDetector;
use BitrixTelegram\Messengers\MessengerFactory;

// Загружаем конфигурацию
$config = require __DIR__ . '/../config/config.php';

// Инициализируем зависимости
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();

$logger = new Logger($config['logging']);
$detector = new MessageDetector();
$tokenRepository = new TokenRepository($pdo);

$messengerFactory = new MessengerFactory($config, $logger, $tokenRepository, $detector);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройка Webhook для мессенджеров</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
        }
        
        .content {
            padding: 30px;
        }
        
        .messenger-section {
            margin-bottom: 40px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .messenger-header {
            background: #f8f9fa;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .messenger-icon {
            font-size: 32px;
        }
        
        .messenger-info h2 {
            font-size: 20px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .messenger-info p {
            color: #666;
            font-size: 14px;
        }
        
        .messenger-body {
            padding: 20px;
        }
        
        .info-block {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .success-block {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .error-block {
            background: #ffebee;
            border-left: 4px solid #f44336;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .warning-block {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .code {
            background: #f5f5f5;
            padding: 10px 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 10px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #5568d3;
        }
        
        .btn-success {
            background: #4caf50;
        }
        
        .btn-success:hover {
            background: #45a049;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-success {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-error {
            background: #ffebee;
            color: #c62828;
        }
        
        .status-warning {
            background: #fff3e0;
            color: #ef6c00;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔗 Настройка Webhook</h1>
            <p>Настройка вебхуков для интеграции мессенджеров с Bitrix24</p>
        </div>
        
        <div class="content">
            <?php
            $webhookUrl = $config['app']['url'] . '/webhook.php';
            
            // Telegram Setup
            echo '<div class="messenger-section">';
            echo '<div class="messenger-header">';
            echo '<div class="messenger-icon">✈️</div>';
            echo '<div class="messenger-info">';
            echo '<h2>Telegram</h2>';
            echo '<p>Настройка бота Telegram</p>';
            echo '</div>';
            echo '</div>';
            echo '<div class="messenger-body">';
            
            if (!empty($config['telegram']['bot_token'])) {
                try {
                    $telegram = $messengerFactory->create('telegram');
                    
                    // Получаем информацию о боте
                    $botInfo = $telegram->getInfo();
                    
                    if (!empty($botInfo['ok'])) {
                        echo '<div class="success-block">';
                        echo '<strong>✓ Бот подключен</strong><br>';
                        echo 'Имя: ' . htmlspecialchars($botInfo['result']['first_name']) . '<br>';
                        echo 'Username: @' . htmlspecialchars($botInfo['result']['username']) . '<br>';
                        echo 'ID: ' . htmlspecialchars($botInfo['result']['id']);
                        echo '</div>';
                        
                        // Устанавливаем webhook
                        if (isset($_GET['setup_telegram'])) {
                            $result = $telegram->setWebhook($webhookUrl);
                            
                            if (!empty($result['ok'])) {
                                echo '<div class="success-block">';
                                echo '<strong>✓ Webhook установлен успешно!</strong><br>';
                                echo 'URL: ' . htmlspecialchars($webhookUrl);
                                echo '</div>';
                            } else {
                                echo '<div class="error-block">';
                                echo '<strong>✗ Ошибка установки webhook</strong><br>';
                                echo htmlspecialchars($result['description'] ?? 'Неизвестная ошибка');
                                echo '</div>';
                            }
                        }
                        
                        // Проверяем текущий webhook
                        $webhookInfo = $telegram->getInfo();
                        
                        echo '<table>';
                        echo '<tr><th>Параметр</th><th>Значение</th></tr>';
                        echo '<tr><td>Webhook URL</td><td>' . htmlspecialchars($webhookUrl) . '</td></tr>';
                        echo '<tr><td>Статус</td><td><span class="status-badge status-success">Активен</span></td></tr>';
                        echo '</table>';
                        
                        echo '<a href="?setup_telegram=1" class="btn btn-success">Установить Webhook</a>';
                        
                    } else {
                        echo '<div class="error-block">';
                        echo '<strong>✗ Ошибка подключения к Telegram API</strong><br>';
                        echo 'Проверьте токен бота в .env';
                        echo '</div>';
                    }
                } catch (\Exception $e) {
                    echo '<div class="error-block">';
                    echo '<strong>✗ Ошибка:</strong> ' . htmlspecialchars($e->getMessage());
                    echo '</div>';
                }
            } else {
                echo '<div class="warning-block">';
                echo '<strong>⚠ Токен бота не настроен</strong><br>';
                echo 'Добавьте TELEGRAM_BOT_TOKEN в файл .env';
                echo '</div>';
            }
            
            echo '<div class="info-block">';
            echo '<strong>ℹ️ Инструкция:</strong><br>';
            echo '1. Создайте бота через @BotFather в Telegram<br>';
            echo '2. Получите токен бота<br>';
            echo '3. Добавьте токен в .env файл<br>';
            echo '4. Нажмите "Установить Webhook"';
            echo '</div>';
            
            echo '</div>';
            echo '</div>';
            
            // Max Setup
            echo '<div class="messenger-section">';
            echo '<div class="messenger-header">';
            echo '<div class="messenger-icon">💬</div>';
            echo '<div class="messenger-info">';
            echo '<h2>Max</h2>';
            echo '<p>Настройка интеграции с Max</p>';
            echo '</div>';
            echo '</div>';
            echo '<div class="messenger-body">';
            
            if (!empty($config['max']['api_key'])) {
                try {
                    $max = $messengerFactory->create('max');
                    
                    if (isset($_GET['setup_max'])) {
                        $result = $max->setWebhook($webhookUrl);
                        
                        if (!empty($result['success'])) {
                            echo '<div class="success-block">';
                            echo '<strong>✓ Webhook установлен успешно!</strong><br>';
                            echo 'URL: ' . htmlspecialchars($webhookUrl);
                            echo '</div>';
                        } else {
                            echo '<div class="error-block">';
                            echo '<strong>✗ Ошибка установки webhook</strong><br>';
                            echo htmlspecialchars($result['error'] ?? 'Неизвестная ошибка');
                            echo '</div>';
                        }
                    }
                    
                    echo '<table>';
                    echo '<tr><th>Параметр</th><th>Значение</th></tr>';
                    echo '<tr><td>Webhook URL</td><td>' . htmlspecialchars($webhookUrl) . '</td></tr>';
                    echo '<tr><td>API URL</td><td>' . htmlspecialchars($config['max']['api_url']) . '</td></tr>';
                    echo '</table>';
                    
                    echo '<a href="?setup_max=1" class="btn btn-success">Установить Webhook</a>';
                    
                } catch (\Exception $e) {
                    echo '<div class="error-block">';
                    echo '<strong>✗ Ошибка:</strong> ' . htmlspecialchars($e->getMessage());
                    echo '</div>';
                }
            } else {
                echo '<div class="warning-block">';
                echo '<strong>⚠ API ключ не настроен</strong><br>';
                echo 'Добавьте MAX_API_KEY в файл .env';
                echo '</div>';
            }
            
            echo '<div class="info-block">';
            echo '<strong>ℹ️ Инструкция:</strong><br>';
            echo '1. Получите API ключ в панели Max<br>';
            echo '2. Добавьте ключ в .env файл<br>';
            echo '3. Нажмите "Установить Webhook"';
            echo '</div>';
            
            echo '</div>';
            echo '</div>';
            
            // Общая информация
            echo '<div class="messenger-section">';
            echo '<div class="messenger-header">';
            echo '<div class="messenger-icon">⚙️</div>';
            echo '<div class="messenger-info">';
            echo '<h2>Общая конфигурация</h2>';
            echo '<p>Параметры системы</p>';
            echo '</div>';
            echo '</div>';
            echo '<div class="messenger-body">';
            
            echo '<table>';
            echo '<tr><th>Параметр</th><th>Значение</th></tr>';
            echo '<tr><td>Webhook URL</td><td><code>' . htmlspecialchars($webhookUrl) . '</code></td></tr>';
            echo '<tr><td>App URL</td><td><code>' . htmlspecialchars($config['app']['url']) . '</code></td></tr>';
            echo '<tr><td>Debug Mode</td><td>' . ($config['app']['debug'] ? '✓ Включен' : '✗ Выключен') . '</td></tr>';
            echo '<tr><td>Logging</td><td>' . ($config['logging']['enabled'] ? '✓ Включен' : '✗ Выключен') . '</td></tr>';
            echo '</table>';
            
            echo '</div>';
            echo '</div>';
            ?>
            
            <div class="info-block" style="margin-top: 30px;">
                <strong>📚 Полезные ссылки:</strong><br>
                <a href="<?php echo $config['app']['url']; ?>/install.php">Установка приложения в Bitrix24</a><br>
                <a href="<?php echo $config['app']['url']; ?>">Главная страница</a>
            </div>
        </div>
    </div>
</body>
</html>