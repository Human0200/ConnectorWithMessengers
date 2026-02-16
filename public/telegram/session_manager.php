<?php
// session_manager.php - Управление сессиями Telegram
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

$config = require __DIR__ . '/../../config/config.php';

// Инициализация
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();
$tokenRepository = new TokenRepository($pdo);
$logger = new Logger($config['logging']);

// Обработка AJAX запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'get_sessions':
                $domain = $_POST['domain'] ?? '';
                if (empty($domain)) {
                    echo json_encode(['success' => false, 'error' => 'Домен не указан']);
                    exit;
                }
                
                // Получаем все сессии через репозиторий
                $sessions = $tokenRepository->getMadelineProtoSessions($domain);
                
                echo json_encode(['success' => true, 'sessions' => $sessions]);
                exit;
                
            case 'create_session':
                $domain = $_POST['domain'] ?? '';
                $sessionName = $_POST['session_name'] ?? '';
                
                if (empty($domain) || empty($sessionName)) {
                    echo json_encode(['success' => false, 'error' => 'Заполните все поля']);
                    exit;
                }
                
                // Генерируем уникальный session_id
                $sessionId = uniqid('tg_', true);
                $sessionFile = $sessionId . '.madeline';
                
                // Создаем сессию через репозиторий
                $result = $tokenRepository->saveMadelineProtoSession(
                    $domain,
                    $sessionId,
                    $sessionFile,
                    $sessionName,
                    null,
                    null,
                    null,
                    'pending' // Статус "ожидает авторизации"
                );
                
                if ($result) {
                    $logger->info('Session created', [
                        'domain' => $domain,
                        'session_id' => $sessionId,
                        'session_name' => $sessionName
                    ]);
                    
                    echo json_encode([
                        'success' => true, 
                        'session_id' => $sessionId,
                        'message' => 'Сессия создана. Перенаправление на авторизацию...'
                    ]);
                } else {
                    throw new \Exception('Не удалось создать сессию');
                }
                exit;
                
            case 'delete_session':
                $sessionId = $_POST['session_id'] ?? '';
                $domain = $_POST['domain'] ?? '';
                
                if (empty($sessionId) || empty($domain)) {
                    echo json_encode(['success' => false, 'error' => 'Некорректные данные']);
                    exit;
                }
                
                // Удаляем через репозиторий
                $result = $tokenRepository->deleteMadelineProtoSession($domain, $sessionId);
                
                if ($result) {
                    $logger->info('Session deleted', [
                        'session_id' => $sessionId,
                        'domain' => $domain
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Сессия удалена']);
                } else {
                    throw new \Exception('Не удалось удалить сессию');
                }
                exit;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
                exit;
        }
    } catch (\Exception $e) {
        $logger->logException($e, 'Session manager error');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Получаем список доменов через репозиторий
$domainsQuery = $pdo->query("SELECT DISTINCT domain FROM bitrix_integration_tokens WHERE domain IS NOT NULL AND domain != ''");
$domains = $domainsQuery->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление сессиями Telegram</title>
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
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            margin-bottom: 20px;
        }
        
        .domain-selector {
            margin-bottom: 25px;
        }
        
        .domain-selector label {
            display: block;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }
        
        .domain-selector select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .domain-selector select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .sessions-list {
            display: none;
        }
        
        .sessions-list.active {
            display: block;
        }
        
        .session-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.2s;
        }
        
        .session-item:hover {
            transform: translateX(5px);
        }
        
        .session-info h3 {
            color: #333;
            margin-bottom: 8px;
            font-size: 1.2rem;
        }
        
        .session-info p {
            color: #666;
            font-size: 0.9rem;
            margin: 4px 0;
        }
        
        .session-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 8px;
        }
        
        .session-status.authorized {
            background: #d4edda;
            color: #155724;
        }
        
        .session-status.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .session-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
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
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-add {
            width: 100%;
            margin-top: 20px;
            padding: 15px;
            font-size: 1.1rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state svg {
            width: 100px;
            height: 100px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .empty-state h2 {
            color: #666;
            margin-bottom: 15px;
        }
        
        .empty-state p {
            color: #999;
            margin-bottom: 25px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            animation: slideDown 0.3s;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            margin-bottom: 25px;
        }
        
        .modal-header h2 {
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        
        .modal-footer .btn {
            flex: 1;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }
        
        .alert.active {
            display: block;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Управление сессиями</h1>
            <p>Telegram MadelineProto Sessions</p>
        </div>
        
        <div class="card">
            <div class="alert alert-success" id="successAlert"></div>
            <div class="alert alert-error" id="errorAlert"></div>
            
            <div class="domain-selector">
                <label for="domainSelect">Выберите домен:</label>
                <select id="domainSelect">
                    <!-- <option value="">-- Выберите домен --</option> -->
                    <?php foreach ($domains as $domain): ?>
                        <option value="<?= htmlspecialchars($domain) ?>"><?= htmlspecialchars($domain) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div id="sessionsContainer" class="sessions-list">
                <div id="sessionsList"></div>
                <button class="btn btn-primary btn-add" id="addSessionBtn">
                    ➕ Добавить новую сессию
                </button>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно создания сессии -->
    <div class="modal" id="createSessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Создание новой сессии</h2>
            </div>
            <form id="createSessionForm">
                <div class="form-group">
                    <label for="sessionName">Название сессии:</label>
                    <input type="text" id="sessionName" name="session_name" placeholder="Например: main_account" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="cancelBtn">Отмена</button>
                    <button type="submit" class="btn btn-success">Создать</button>
                </div>
            </form>
        </div>
    </div>
    <script src="https://api.bitrix24.com/api/v1/"></script>
    <script>
        const domainSelect = document.getElementById('domainSelect');
        const sessionsContainer = document.getElementById('sessionsContainer');
        const sessionsList = document.getElementById('sessionsList');
        const addSessionBtn = document.getElementById('addSessionBtn');
        const createSessionModal = document.getElementById('createSessionModal');
        const createSessionForm = document.getElementById('createSessionForm');
        const cancelBtn = document.getElementById('cancelBtn');
        const successAlert = document.getElementById('successAlert');
        const errorAlert = document.getElementById('errorAlert');
        
        let currentDomain = '';
        
        // Показать уведомление
        function showAlert(message, type = 'success') {
            const alert = type === 'success' ? successAlert : errorAlert;
            alert.textContent = message;
            alert.classList.add('active');
            
            setTimeout(() => {
                alert.classList.remove('active');
            }, 5000);
        }
        
        // Загрузка сессий
        async function loadSessions(domain) {
            currentDomain = domain;
            sessionsList.innerHTML = '<div class="loading"><div class="spinner"></div><p>Загрузка сессий...</p></div>';
            sessionsContainer.classList.add('active');
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_sessions');
                formData.append('domain', domain);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    displaySessions(data.sessions);
                } else {
                    throw new Error(data.error || 'Ошибка загрузки');
                }
            } catch (error) {
                sessionsList.innerHTML = `<div class="empty-state"><h2>Ошибка</h2><p>${error.message}</p></div>`;
            }
        }
        
        // Отображение сессий
        function displaySessions(sessions) {
            if (sessions.length === 0) {
                sessionsList.innerHTML = `
                    <div class="empty-state">
                        <svg fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 2a8 8 0 100 16 8 8 0 000-16zM9 9a1 1 0 012 0v4a1 1 0 11-2 0V9zm1-5a1 1 0 100 2 1 1 0 000-2z"/>
                        </svg>
                        <h2>Нет активных сессий</h2>
                        <p>Создайте первую сессию для начала работы</p>
                    </div>
                `;
                return;
            }
            
            sessionsList.innerHTML = sessions.map(session => {
                const isAuthorized = session.status === 'authorized';
                const statusClass = isAuthorized ? 'authorized' : 'pending';
                const statusText = isAuthorized ? '✓ Авторизован' : '⏳ Ожидает авторизации';
                
                return `
                    <div class="session-item">
                        <div class="session-info">
                            <h3>${session.session_name}</h3>
                            <p>ID: ${session.session_id}</p>
                            ${session.account_first_name ? `
                                <p>Аккаунт: ${session.account_first_name} ${session.account_username ? '@' + session.account_username : ''}</p>
                            ` : ''}
                            <span class="session-status ${statusClass}">
                                ${statusText}
                            </span>
                        </div>
                        <div class="session-actions">
                            ${!isAuthorized ? `
                                <a href="qr_auth.php?session_id=${session.session_id}&domain=${currentDomain}" class="btn btn-success">
                                    Авторизовать
                                </a>
                            ` : ''}
                            <button class="btn btn-danger" onclick="deleteSession('${session.session_id}')">
                                Удалить
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        // Удаление сессии
        async function deleteSession(sessionId) {
            if (!confirm('Вы уверены, что хотите удалить эту сессию?')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete_session');
                formData.append('session_id', sessionId);
                formData.append('domain', currentDomain);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('Сессия успешно удалена', 'success');
                    loadSessions(currentDomain);
                } else {
                    throw new Error(data.error);
                }
            } catch (error) {
                showAlert('Ошибка удаления: ' + error.message, 'error');
            }
        }

        // События
        domainSelect.addEventListener('change', (e) => {
            const domain = e.target.value;
            if (domain) {
                loadSessions(domain);
            } else {
                sessionsContainer.classList.remove('active');
            }
        });
        
        addSessionBtn.addEventListener('click', () => {
            createSessionModal.classList.add('active');
        });
        
        cancelBtn.addEventListener('click', () => {
            createSessionModal.classList.remove('active');
            createSessionForm.reset();
        });
        
        createSessionForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const sessionName = document.getElementById('sessionName').value;
            
            try {
                const formData = new FormData();
                formData.append('action', 'create_session');
                formData.append('domain', currentDomain);
                formData.append('session_name', sessionName);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert(data.message, 'success');
                    createSessionModal.classList.remove('active');
                    createSessionForm.reset();
                    
                    // Перенаправляем на авторизацию
                    setTimeout(() => {
                        window.location.href = `qr_auth.php?session_id=${data.session_id}&domain=${currentDomain}`;
                    }, 1000);
                } else {
                    throw new Error(data.error);
                }
            } catch (error) {
                showAlert('Ошибка создания: ' + error.message, 'error');
            }
        });
        
        // Закрытие модального окна по клику вне его
        createSessionModal.addEventListener('click', (e) => {
            if (e.target === createSessionModal) {
                createSessionModal.classList.remove('active');
                createSessionForm.reset();
            }
        });
    </script>
</body>
</html>