<?php
// public/handler_madelineproto_simple.php

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

header('Content-Type: application/json');

$config = require __DIR__ . '/../config/config.php';
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();
$logger = new Logger($config['logging']);
$tokenRepository = new TokenRepository($pdo);

$method = $_GET['method'] ?? '';
$domain = $_GET['domain'] ?? '';

if (!$domain) {
    echo json_encode(['success' => false, 'error' => 'Domain required']);
    exit;
}

// Проверяем домен в БД
$tokenData = $tokenRepository->findByDomain($domain);
if (!$tokenData) {
    echo json_encode(['success' => false, 'error' => 'Domain not found']);
    exit;
}

// Путь к сессии
$sessionPath = __DIR__ . '/../storage/sessions';
$sessionFile = $sessionPath . '/madeline_' . md5($domain) . '.session';

switch ($method) {
    case 'status':
        $status = [
            'domain' => $domain,
            'session_file' => basename($sessionFile),
            'session_exists' => file_exists($sessionFile),
            'bitrix_integration' => true
        ];
        
        if (file_exists($sessionFile)) {
            $status['file_size'] = filesize($sessionFile);
            $status['file_time'] = date('Y-m-d H:i:s', filemtime($sessionFile));
            
            // Пробуем проверить сессию
            try {
                $settings = new \danog\MadelineProto\Settings;
                $appInfo = new \danog\MadelineProto\Settings\AppInfo;
                $appInfo->setApiId($config['telegram']['api_id']);
                $appInfo->setApiHash($config['telegram']['api_hash']);
                $settings->setAppInfo($appInfo);
                
                $madelineProto = new \danog\MadelineProto\API($sessionFile, $settings);
                $me = $madelineProto->getSelf();
                
                if ($me) {
                    $status['authorized'] = true;
                    $status['account'] = [
                        'id' => $me['id'] ?? null,
                        'first_name' => $me['first_name'] ?? null,
                        'username' => $me['username'] ?? null
                    ];
                } else {
                    $status['authorized'] = false;
                }
            } catch (Exception $e) {
                $status['authorized'] = false;
                $status['error'] = $e->getMessage();
            }
        } else {
            $status['authorized'] = false;
        }
        
        echo json_encode(['success' => true, 'data' => $status]);
        break;
        
    case 'auth':
        // Простая инструкция для ручной авторизации
        $instructions = [
            '1. Убедитесь что файл сессии не существует или удалите его',
            '2. Запустите консольную команду:',
            '   cd ' . realpath(__DIR__ . '/..'),
            '   php -r \'$m = new \danog\MadelineProto\API("storage/sessions/madeline_' . md5($domain) . '.session", ["app_info" => ["api_id" => ' . $config['telegram']['api_id'] . ', "api_hash" => "' . $config['telegram']['api_hash'] . '"]]); $m->start();\'',
            '3. Отсканируйте QR-код который появится в терминале',
            '4. После авторизации проверьте статус'
        ];
        
        echo json_encode([
            'success' => true,
            'instructions' => $instructions,
            'api_id' => $config['telegram']['api_id'],
            'session_file' => 'storage/sessions/madeline_' . md5($domain) . '.session'
        ]);
        break;
        
    case 'send':
        $chatId = $_GET['chat_id'] ?? '';
        $message = $_GET['message'] ?? 'Test message';
        
        if (!$chatId) {
            echo json_encode(['success' => false, 'error' => 'Chat ID required']);
            exit;
        }
        
        if (!file_exists($sessionFile)) {
            echo json_encode(['success' => false, 'error' => 'Session file not found']);
            exit;
        }
        
        try {
            $settings = new \danog\MadelineProto\Settings;
            $appInfo = new \danog\MadelineProto\Settings\AppInfo;
            $appInfo->setApiId($config['telegram']['api_id']);
            $appInfo->setApiHash($config['telegram']['api_hash']);
            $settings->setAppInfo($appInfo);
            
            $madelineProto = new \danog\MadelineProto\API($sessionFile, $settings);
            $result = $madelineProto->messages->sendMessage([
                'peer' => $chatId,
                'message' => $message
            ]);
            
            echo json_encode([
                'success' => true,
                'message_id' => $result['id'] ?? null
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode([
            'success' => false,
            'error' => 'Invalid method',
            'available_methods' => ['status', 'auth', 'send']
        ]);
}