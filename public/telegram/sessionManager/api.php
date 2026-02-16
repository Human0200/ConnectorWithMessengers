<?php
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

$config = require __DIR__ . '/../../../config/config.php';

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
