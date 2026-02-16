<?php
// qr_auth.php - QR авторизация для MadelineProto
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Services\MadelineProtoService;


$config = require __DIR__ . '/../../config/config.php';

// Инициализация
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();
$tokenRepository = new TokenRepository($pdo);
$logger = new Logger($config['logging']);

$madelineService = new MadelineProtoService(
    $tokenRepository,
    $logger,
    $config['telegram']['api_id'],
    $config['telegram']['api_hash'],
    $config['sessions']['path'] ?? null
);

// AJAX обработчики - ПЕРЕД любой HTML-выдачей
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Включаем вывод ошибок для отладки
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        // Получаем параметры из POST для AJAX
        $sessionId = $_POST['session_id'] ?? ($_GET['session_id'] ?? '');
        $domain = $_POST['domain'] ?? ($_GET['domain'] ?? '');
        
        $logger->info('AJAX request received', [
            'action' => $action,
            'session_id' => $sessionId,
            'domain' => $domain
        ]);
        
        if (empty($sessionId) || empty($domain)) {
            throw new \Exception('Не указаны обязательные параметры');
        }
        
        // Получаем информацию о сессии
        $sessionInfo = $tokenRepository->getMadelineProtoSession($domain, $sessionId);
        
        if (!$sessionInfo) {
            throw new \Exception('Сессия не найдена в базе данных');
        }
        
        switch ($action) {
            case 'start_auth':
                // Создаем или получаем экземпляр MadelineProto
                $instance = $madelineService->createOrGetInstance($domain, $sessionId);
                
                if (!$instance) {
                    throw new \Exception('Не удалось инициализировать сессию');
                }
                
                // Проверяем, авторизован ли уже
                try {
                    $self = $instance->getSelf();
                    
                    if ($self && isset($self['id'])) {
                        // Уже авторизован
                        $tokenRepository->saveMadelineProtoSession(
                            $domain,
                            $sessionId,
                            $sessionInfo['session_file'],
                            $sessionInfo['session_name'],
                            $self['id'] ?? null,
                            $self['username'] ?? null,
                            $self['first_name'] ?? null,
                            'authorized'
                        );
                        
                        echo json_encode([
                            'success' => true,
                            'authorized' => true,
                            'user' => $self
                        ]);
                        exit;
                    }
                } catch (\Exception $e) {
                    // Не авторизован, продолжаем получение QR-кода
                    $logger->debug('Not authorized yet, proceeding to QR login', [
                        'session_id' => $sessionId
                    ]);
                }
                
                // Получаем QR-код для авторизации
                try {
                    $qrLogin = $instance->qrLogin();
                    
                    // qrLogin возвращает объект LoginQrCode, а не массив
                    $qrLink = null;
                    if (is_object($qrLogin)) {
                        // Пробуем получить ссылку из объекта
                        if (isset($qrLogin->link)) {
                            $qrLink = $qrLogin->link;
                        } elseif (method_exists($qrLogin, 'getLink')) {
                        } else {
                            // Если это объект с токеном
                            $token = $qrLogin->token ?? null;
                            if ($token) {
                                $qrLink = "tg://login?token=" . base64_encode($token);
                            }
                        }
                    }
                    
                    $logger->info('QR login response', [
                        'qr_link' => $qrLink,
                        'type' => is_object($qrLogin) ? get_class($qrLogin) : gettype($qrLogin)
                    ]);
                    
                    echo json_encode([
                        'success' => true,
                        'authorized' => false,
                        'qr_link' => $qrLink
                    ]);
                } catch (\Exception $e) {
                    $logger->error('QR login failed', [
                        'error' => $e->getMessage()
                    ]);
                    throw new \Exception('Не удалось получить QR-код: ' . $e->getMessage());
                }
                exit;
                
            case 'check_auth':
                // Проверяем статус авторизации
                $instance = $madelineService->getInstance($domain, $sessionId);
                
                if (!$instance) {
                    // Пробуем создать новый экземпляр
                    $instance = $madelineService->createOrGetInstance($domain, $sessionId);
                    if (!$instance) {
                        throw new \Exception('Не удалось инициализировать сессию');
                    }
                }
                
                try {
                    $user = $instance->getSelf();
                    
                    if ($user) {
                        // Авторизация успешна
                        $tokenRepository->saveMadelineProtoSession(
                            $domain,
                            $sessionId,
                            $sessionInfo['session_file'],
                            $sessionInfo['session_name'],
                            $user['id'] ?? null,
                            $user['username'] ?? null,
                            $user['first_name'] ?? null,
                            'authorized'
                        );
                        
                        $logger->info('Session authorized successfully', [
                            'domain' => $domain,
                            'session_id' => $sessionId,
                            'user_id' => $user['id'] ?? null
                        ]);
                        
                        echo json_encode([
                            'success' => true,
                            'authorized' => true,
                            'user' => [
                                'id' => $user['id'] ?? null,
                                'username' => $user['username'] ?? null,
                                'first_name' => $user['first_name'] ?? null,
                                'last_name' => $user['last_name'] ?? null,
                            ]
                        ]);
                        exit;
                    }
                } catch (\Exception $e) {
                    // Еще не авторизован
                    $logger->debug('Not authorized yet', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage()
                    ]);
                }
                
                echo json_encode([
                    'success' => true,
                    'authorized' => false
                ]);
                exit;
                
            case 'refresh_qr':
                // Обновляем QR-код
                $instance = $madelineService->createOrGetInstance($domain, $sessionId);
                
                if (!$instance) {
                    throw new \Exception('Не удалось инициализировать сессию');
                }
                
                // Сбрасываем текущую сессию QR
                try {
                    if (isset($instance->qrLogin)) {
                        unset($instance->qrLogin);
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки сброса
                }
                
                // Получаем новый QR
                try {
                    $qrLogin = $instance->qrLogin();
                    $qrLink = null;
                    
                    if (is_object($qrLogin)) {
                        if (isset($qrLogin->link)) {
                            $qrLink = $qrLogin->link;
                        } elseif (method_exists($qrLogin, 'getLink')) {
                        } else {
                            $token = $qrLogin->token ?? null;
                            if ($token) {
                                $qrLink = "tg://login?token=" . base64_encode($token);
                            }
                        }
                    }
                    
                    $logger->info('New QR generated', [
                        'session_id' => $sessionId,
                        'qr_link' => $qrLink ? 'generated' : 'null'
                    ]);
                    
                    if (!$qrLink) {
                        throw new \Exception('Не удалось сгенерировать QR-ссылку');
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'qr_link' => $qrLink
                    ]);
                    
                } catch (\Exception $e) {
                    $logger->error('QR refresh failed', [
                        'error' => $e->getMessage()
                    ]);
                    throw new \Exception('Не удалось обновить QR-код: ' . $e->getMessage());
                }
                exit;
                
            default:
                throw new \Exception('Неизвестное действие: ' . $action);
        }
        
    } catch (\Throwable $e) {
        $logger->error('QR Auth error', [
            'action' => $action ?? 'unknown',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        http_response_code(200); // Всегда возвращаем 200, чтобы JSON обработался
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage(),
            'debug' => [
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ]
        ]);
        exit;
    }
}

// Для GET-запросов (отображение HTML)
$sessionId = $_GET['session_id'] ?? '';
$domain = $_GET['domain'] ?? '';

if (empty($sessionId) || empty($domain)) {
    die('Ошибка: не указаны обязательные параметры (session_id, domain)');
}

// Получаем информацию о сессии для HTML
$sessionInfo = $tokenRepository->getMadelineProtoSession($domain, $sessionId);

if (!$sessionInfo) {
    die('Ошибка: сессия не найдена');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Авторизация - <?= htmlspecialchars($sessionInfo['session_name']) ?></title>
    <script src="https://api.bitrix24.com/api/v1/"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            max-width: 600px;
            width: 100%;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        
        .header {
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #333;
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 1rem;
        }
        
        .session-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        
        .session-info h3 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 1.3rem;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
        }
        
        .info-value {
            color: #888;
        }
        
        .qr-container {
            display: none;
            margin: 30px 0;
        }
        
        .qr-container.active {
            display: block;
        }
        
        #qrcode {
            display: inline-block;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .instructions {
            margin-top: 20px;
            padding: 20px;
            background: #e3f2fd;
            border-radius: 12px;
            color: #1976d2;
        }
        
        .instructions h4 {
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        
        .instructions ol {
            text-align: left;
            padding-left: 20px;
        }
        
        .instructions li {
            margin: 8px 0;
        }
        
        .success-container {
            display: none;
            margin: 30px 0;
        }
        
        .success-container.active {
            display: block;
        }
        
        .success-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 20px;
            background: #4caf50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 50px;
        }
        
        .user-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }
        
        .user-info h3 {
            color: #4caf50;
            margin-bottom: 15px;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            margin: 10px 5px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: #4caf50;
            color: white;
        }
        
        .btn-success:hover {
            background: #45a049;
        }
        
        .loading {
            margin: 30px 0;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .status-message {
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
            font-weight: 500;
        }
        
        .status-info {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .status-error {
            background: #ffebee;
            color: #c62828;
        }
        
        .timer {
            font-size: 0.9rem;
            color: #888;
            margin-top: 10px;
        }
        
        .qr-timer {
            font-size: 0.9rem;
            color: #ff9800;
            font-weight: 600;
            margin-top: 10px;
            padding: 8px 15px;
            background: #fff3e0;
            border-radius: 20px;
            display: block;
        }
        
        .qr-expired {
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>🔐 QR Авторизация</h1>
                <p>Telegram MadelineProto</p>
            </div>
            
            <div class="session-info">
                <h3>Информация о сессии</h3>
                <div class="info-item">
                    <span class="info-label">Название:</span>
                    <span class="info-value"><?= htmlspecialchars($sessionInfo['session_name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Домен:</span>
                    <span class="info-value"><?= htmlspecialchars($domain) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Session ID:</span>
                    <span class="info-value"><?= htmlspecialchars($sessionId) ?></span>
                </div>
            </div>
            
            <!-- Загрузка -->
            <div id="loadingContainer" class="loading">
                <div class="spinner"></div>
                <p>Инициализация...</p>
            </div>
            
            <!-- QR-код -->
            <div id="qrContainer" class="qr-container">
                <div id="qrcode"></div>
                <div class="qr-timer" id="qrTimer">
                    QR обновится через: <span id="qrCountdown">15</span>с
                </div>
                <div class="instructions">
                    <h4>📱 Как авторизоваться:</h4>
                    <ol>
                        <li>Откройте Telegram на телефоне</li>
                        <li>Перейдите в Настройки → Устройства → Подключить устройство</li>
                        <li>Отсканируйте QR-код выше</li>
                        <li>Дождитесь подтверждения авторизации</li>
                    </ol>
                </div>
                <div class="timer" id="timer">
                    Проверка авторизации: <span id="countdown">0</span>с
                </div>
            </div>
            
            <!-- Успешная авторизация -->
            <div id="successContainer" class="success-container">
                <div class="success-icon">✓</div>
                <h2 style="color: #4caf50; margin-bottom: 15px;">Авторизация успешна!</h2>
                <p style="color: #666; margin-bottom: 20px;">Ваша сессия активирована</p>
                
                <div id="userInfo" class="user-info"></div>
            </div>
            
            <!-- Сообщения об ошибках -->
            <div id="statusMessage"></div>
        </div>
    </div>

    <script>
        // Параметры из PHP
        const SESSION_ID = '<?= htmlspecialchars($sessionId) ?>';
        const DOMAIN = '<?= htmlspecialchars($domain) ?>';
        const QR_REFRESH_INTERVAL = 15000; // 15 секунд
        
        let checkInterval = null;
        let countdownTimer = null;
        let qrRefreshTimer = null;
        let secondsElapsed = 0;
        let qrRefreshSeconds = 0;
        let qrCodeInstance = null;
        
        const loadingContainer = document.getElementById('loadingContainer');
        const qrContainer = document.getElementById('qrContainer');
        const successContainer = document.getElementById('successContainer');
        const userInfoDiv = document.getElementById('userInfo');
        const statusMessage = document.getElementById('statusMessage');
        const countdownSpan = document.getElementById('countdown');
        const qrCountdownSpan = document.getElementById('qrCountdown');
        const qrTimer = document.getElementById('qrTimer');
        
        // Показать сообщение
        function showMessage(message, type = 'info') {
            statusMessage.innerHTML = `<div class="status-message status-${type}">${message}</div>`;
        }
        
        // Запуск авторизации
        async function startAuth() {
            try {
                const formData = new FormData();
                formData.append('action', 'start_auth');
                formData.append('session_id', SESSION_ID);
                formData.append('domain', DOMAIN);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const text = await response.text();
                console.log('===== SERVER RESPONSE =====');
                console.log(text);
                console.log('===========================');
                
                const data = JSON.parse(text);
                
                if (!data.success) {
                    throw new Error(data.error || 'Ошибка запуска авторизации');
                }
                
                loadingContainer.style.display = 'none';
                
                if (data.authorized) {
                    showSuccess(data.user);
                } else {
                    if (!data.qr_link) {
                        throw new Error('QR-ссылка не получена от сервера');
                    }
                    showQRCode(data.qr_link);
                    startChecking();
                }
                
            } catch (error) {
                loadingContainer.style.display = 'none';
                showMessage('Ошибка: ' + error.message, 'error');
                console.error('Full error:', error);
            }
        }
        
        // Показать QR-код
        function showQRCode(link) {
            qrContainer.classList.add('active');
            
            // Очищаем предыдущий QR-код
            document.getElementById('qrcode').innerHTML = '';
            
            // Сбрасываем таймер QR
            qrRefreshSeconds = 0;
            qrCountdownSpan.textContent = Math.floor(QR_REFRESH_INTERVAL / 1000);
            qrTimer.classList.remove('qr-expired');
            
            // Генерируем новый QR-код
            qrCodeInstance = new QRCode(document.getElementById('qrcode'), {
                text: link,
                width: 256,
                height: 256,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        }
        
        // Функция обновления QR-кода
        async function refreshQRCode() {
            try {
                console.log('Обновление QR-кода...');
                
                const formData = new FormData();
                formData.append('action', 'refresh_qr');
                formData.append('session_id', SESSION_ID);
                formData.append('domain', DOMAIN);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success && data.qr_link) {
                    showQRCode(data.qr_link);
                    showMessage('QR-код обновлен', 'info');
                } else {
                    console.warn('Не удалось обновить QR-код:', data.error);
                    showMessage('Не удалось обновить QR-код', 'error');
                }
                
            } catch (error) {
                console.error('Ошибка обновления QR:', error);
                showMessage('Ошибка обновления QR-кода', 'error');
            }
        }
        
        // Проверка статуса авторизации
        async function checkAuth() {
            try {
                const formData = new FormData();
                formData.append('action', 'check_auth');
                formData.append('session_id', SESSION_ID);
                formData.append('domain', DOMAIN);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                console.log('Check auth response:', data);
                if (data.success && data.authorized) {
                    stopChecking();
                    showSuccess(data.user);
                }
                
            } catch (error) {
                console.error('Ошибка проверки авторизации:', error);
            }
        }
        
        // Запуск периодической проверки
        function startChecking() {
            secondsElapsed = 0;
            qrRefreshSeconds = 0;
            
            // Проверяем авторизацию каждые 3 секунды
            checkInterval = setInterval(checkAuth, 3000);
            
            // Обновляем QR-код каждые 15 секунд
            qrRefreshTimer = setInterval(refreshQRCode, QR_REFRESH_INTERVAL);
            
            // Таймеры обратного отсчета
            countdownTimer = setInterval(() => {
                secondsElapsed++;
                qrRefreshSeconds++;
                
                countdownSpan.textContent = secondsElapsed;
                
                // Обновляем таймер QR
                const remainingSeconds = Math.max(0, Math.floor(QR_REFRESH_INTERVAL / 1000) - qrRefreshSeconds);
                qrCountdownSpan.textContent = remainingSeconds;
                
                // Подсвечиваем когда осталось мало времени
                if (remainingSeconds <= 5) {
                    qrTimer.classList.add('qr-expired');
                } else {
                    qrTimer.classList.remove('qr-expired');
                }
                
            }, 1000);
        }
        
        // Остановка проверки
        function stopChecking() {
            if (checkInterval) {
                clearInterval(checkInterval);
                checkInterval = null;
            }
            if (countdownTimer) {
                clearInterval(countdownTimer);
                countdownTimer = null;
            }
            if (qrRefreshTimer) {
                clearInterval(qrRefreshTimer);
                qrRefreshTimer = null;
            }
        }
        
        // Показать успешную авторизацию
        function showSuccess(user) {
            qrContainer.classList.remove('active');
            successContainer.classList.add('active');
            
            userInfoDiv.innerHTML = `
                <h3>Информация об аккаунте</h3>
                <div class="info-item">
                    <span class="info-label">ID:</span>
                    <span class="info-value">${user.id || 'N/A'}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Имя:</span>
                    <span class="info-value">${user.first_name || ''} ${user.last_name || ''}</span>
                </div>
                ${user.username ? `
                    <div class="info-item">
                        <span class="info-label">Username:</span>
                        <span class="info-value">@${user.username}</span>
                    </div>
                ` : ''}
            `;
        }
        
        // Запуск при загрузке страницы
        document.addEventListener('DOMContentLoaded', () => {
            startAuth();
        });
        
        // Очистка при закрытии страницы
        window.addEventListener('beforeunload', () => {
            stopChecking();
        });
        
        // Автоматическое обновление QR по клику на таймер
        qrTimer.addEventListener('click', () => {
            refreshQRCode();
        });
    </script>
</body>
</html>