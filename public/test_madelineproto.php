<?php
// test_qr_auth.php

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Services\MadelineProtoService;
use BitrixTelegram\Messengers\MadelineProtoMessenger;

$config = require __DIR__ . '/../config/config.php';

// Цвета для консоли
function colorize($text, $color = 'white') {
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m",
    ];
    return ($colors[$color] ?? $colors['white']) . $text . $colors['reset'];
}

function printHeader($text) {
    echo "\n" . colorize(str_repeat("=", 70), 'cyan') . "\n";
    echo colorize($text, 'cyan') . "\n";
    echo colorize(str_repeat("=", 70), 'cyan') . "\n\n";
}

function printSubHeader($text) {
    echo "\n" . colorize(str_repeat("-", 70), 'blue') . "\n";
    echo colorize($text, 'blue') . "\n";
    echo colorize(str_repeat("-", 70), 'blue') . "\n";
}

function success($text) {
    echo colorize("✅ " . $text, 'green') . "\n";
}

function error($text) {
    echo colorize("❌ " . $text, 'red') . "\n";
}

function warning($text) {
    echo colorize("⚠️  " . $text, 'yellow') . "\n";
}

function info($text) {
    echo colorize("ℹ️  " . $text, 'cyan') . "\n";
}

function prompt($text, $default = '') {
    echo colorize($text, 'yellow');
    if ($default) {
        echo colorize(" [{$default}]", 'white');
    }
    echo colorize(": ", 'yellow');
    $input = trim(fgets(STDIN));
    return $input ?: $default;
}

printHeader("🔐 ТЕСТ QR АВТОРИЗАЦИИ MADELINEPROTO");

// 1. Инициализация
printSubHeader("1. ИНИЦИАЛИЗАЦИЯ");

try {
    $database = Database::getInstance($config['database']);
    $pdo = $database->getConnection();
    $logger = new Logger($config['logging']);
    $tokenRepository = new TokenRepository($pdo);

    $madelineService = new MadelineProtoService(
        $tokenRepository,
        $logger,
        $config['telegram']['api_id'],
        $config['telegram']['api_hash'],
        $config['sessions']['path'] ?? null
    );

    success("База данных подключена");
    success("Логгер инициализирован");
    success("MadelineProtoService создан");
    info("API ID: " . $config['telegram']['api_id']);
    info("Путь к сессиям: " . ($config['sessions']['path'] ?? 'default'));

} catch (\Exception $e) {
    error("Ошибка инициализации: " . $e->getMessage());
    exit(1);
}

// 2. Выбор домена
printSubHeader("2. ВЫБОР ДОМЕНА");

$domain = prompt("Введите домен Bitrix24", 'b24-ern8dn.bitrix24.ru');

$tokenData = $tokenRepository->findByDomain($domain);
if (!$tokenData) {
    error("Домен '{$domain}' не найден в БД");
    warning("Сначала установите интеграцию Bitrix24");
    exit(1);
}

success("Домен найден в БД");
info("Connector ID: " . ($tokenData['connector_id'] ?? 'не установлен'));

// 3. Показать существующие сессии
printSubHeader("3. СУЩЕСТВУЮЩИЕ СЕССИИ");

$sessions = $madelineService->getDomainSessions($domain);
echo "Найдено сессий: " . colorize(count($sessions), 'magenta') . "\n\n";

if (!empty($sessions)) {
    echo colorize("ID | Статус      | Имя сессии                    | Аккаунт", 'cyan') . "\n";
    echo str_repeat("-", 70) . "\n";
    
    foreach ($sessions as $index => $session) {
        $num = str_pad($index + 1, 2, ' ', STR_PAD_LEFT);
        $status = $session['status'] === 'authorized' ? colorize('authorized ', 'green') : colorize($session['status'], 'yellow');
        $name = str_pad(substr($session['session_name'], 0, 30), 30);
        $account = '';
        
        if ($session['account_first_name']) {
            $account = $session['account_first_name'] . ' (@' . ($session['account_username'] ?? 'N/A') . ')';
        } else {
            $account = '-';
        }
        
        echo "{$num} | {$status} | {$name} | {$account}\n";
    }
    
    echo "\n";
    $useExisting = prompt("Использовать существующую сессию? (y/n)", 'n');
    
    if (strtolower($useExisting) === 'y') {
        $sessionNum = (int)prompt("Введите номер сессии (1-" . count($sessions) . ")") - 1;
        
        if (isset($sessions[$sessionNum])) {
            $sessionId = $sessions[$sessionNum]['session_id'];
            $sessionName = $sessions[$sessionNum]['session_name'];
            success("Используем существующую сессию: {$sessionName}");
            goto TEST_EXISTING_SESSION;
        } else {
            error("Неверный номер сессии");
        }
    }
}

// 4. Создание новой сессии
printSubHeader("4. СОЗДАНИЕ НОВОЙ СЕССИИ");

$sessionName = prompt("Введите имя для новой сессии", 'QR Session ' . date('H:i'));
$sessionId = $madelineService->generateSessionId();

info("Создание сессии...");
info("  Домен: {$domain}");
info("  ID: {$sessionId}");
info("  Имя: {$sessionName}");

$createResult = $madelineService->createSession($domain, $sessionId, $sessionName);
if (!$createResult['success']) {
    error("Не удалось создать сессию");
    exit(1);
}

success("Сессия создана");

// 5. Запуск интерактивной авторизации
printSubHeader("5. ИНТЕРАКТИВНАЯ АВТОРИЗАЦИЯ");

echo "\n";
echo colorize("┌" . str_repeat("─", 68) . "┐", 'cyan') . "\n";
echo colorize("│ 📱 ЗАПУСК ИНТЕРАКТИВНОЙ АВТОРИЗАЦИИ MADELINEPROTO" . str_repeat(" ", 19) . "│", 'cyan') . "\n";
echo colorize("└" . str_repeat("─", 68) . "┘", 'cyan') . "\n";
echo "\n";

echo colorize("📋 ИНСТРУКЦИЯ:", 'cyan') . "\n";
echo "  1. MadelineProto отобразит QR-код прямо в консоли\n";
echo "  2. Откройте Telegram на телефоне\n";
echo "  3. Перейдите в " . colorize("Настройки → Устройства → Подключить устройство", 'green') . "\n";
echo "  4. Отсканируйте QR-код который появится ниже\n";
echo "  5. Или введите номер телефона для авторизации по SMS\n";
echo "\n";

warning("ВНИМАНИЕ: Процесс авторизации начнется через 3 секунды...");
echo "\n";

sleep(3);

info("Запуск интерактивной авторизации...");
echo "\n";
echo colorize(str_repeat("=", 70), 'blue') . "\n\n";

// Запускаем интерактивную авторизацию
// MadelineProto сам покажет QR-код и обработает авторизацию
$authResult = $madelineService->startInteractiveAuth($domain, $sessionId);

echo "\n";
echo colorize(str_repeat("=", 70), 'blue') . "\n";
echo "\n";

if (!$authResult['success']) {
    error("Не удалось завершить авторизацию");
    error("Ошибка: " . ($authResult['error'] ?? 'неизвестная ошибка'));
    
    // Детальная диагностика
    printSubHeader("🔧 ДИАГНОСТИКА");
    
    $sessionFile = $madelineService->getSessionFile($domain, $sessionId);
    info("Файл сессии: {$sessionFile}");
    info("Файл существует: " . (file_exists($sessionFile) ? 'да' : 'нет'));
    
    if (file_exists($sessionFile)) {
        info("Размер файла: " . filesize($sessionFile) . " байт");
        info("Права доступа: " . substr(sprintf('%o', fileperms($sessionFile)), -4));
    }
    
    exit(1);
}

success("Авторизация завершена успешно!");
$authorized = true;
$accountData = $authResult['account'];
echo "\n";

// 7. Информация об аккаунте
printSubHeader("7. ИНФОРМАЦИЯ ОБ АККАУНТЕ");

if ($accountData) {
    echo colorize("👤 Авторизованный аккаунт:", 'green') . "\n";
    echo "  • ID: " . colorize($accountData['id'] ?? 'N/A', 'white') . "\n";
    echo "  • Имя: " . colorize(($accountData['first_name'] ?? '') . ' ' . ($accountData['last_name'] ?? ''), 'white') . "\n";
    echo "  • Username: " . colorize('@' . ($accountData['username'] ?? 'не указан'), 'white') . "\n";
    echo "  • Телефон: " . colorize($accountData['phone'] ?? 'не указан', 'white') . "\n";
    echo "\n";
}

TEST_EXISTING_SESSION:

// 8. Тест мессенджера
printSubHeader("8. ТЕСТ MESSENGER INTERFACE");

$messenger = new MadelineProtoMessenger($logger, $tokenRepository, $madelineService);
$messenger->setDomain($domain);
$messenger->setSessionId($sessionId);

success("MadelineProtoMessenger создан");
info("  Домен: " . $messenger->getDomain());
info("  Session ID: " . $messenger->getSessionId());

// Проверка активности
if ($messenger->isSessionActive()) {
    success("Сессия активна");
} else {
    error("Сессия не активна");
}

// Получение информации через messenger
$messengerInfo = $messenger->getInfo();
if ($messengerInfo['success']) {
    success("Информация получена через Messenger API");
}

// 9. Тест отправки сообщения
printSubHeader("9. ТЕСТ ОТПРАВКИ СООБЩЕНИЯ");

$sendTest = prompt("Отправить тестовое сообщение? (y/n)", 'y');

if (strtolower($sendTest) === 'y') {
    $chatId = prompt("Введите chat_id (или Enter для себя)", '');
    
    if (empty($chatId)) {
        // Используем ID текущего аккаунта
        if (isset($accountData['id'])) {
            $chatId = $accountData['id'];
        } elseif (isset($messengerInfo['id'])) {
            $chatId = $messengerInfo['id'];
        } else {
            $chatId = '753744248'; // fallback
        }
        info("Используем ID текущего аккаунта: {$chatId}");
    }
    
    // Формируем префикс
    if (!str_starts_with($chatId, 'tguser_') && is_numeric($chatId)) {
        $chatId = 'tguser_' . $chatId;
    }
    
    $testMessage = "✅ Тестовое сообщение через QR-авторизацию\n\n" .
                   "🕐 Время: " . date('Y-m-d H:i:s') . "\n" .
                   "📋 Сессия: {$sessionName}\n" .
                   "🌐 Домен: {$domain}\n" .
                   "🔐 Метод: QR Code";
    
    info("Отправка сообщения...");
    info("  Кому: {$chatId}");
    info("  Длина: " . strlen($testMessage) . " символов");
    
    $sendResult = $messenger->sendMessage($chatId, $testMessage);
    
    if ($sendResult['success']) {
        success("Сообщение отправлено!");
        info("  Message ID: " . ($sendResult['message_id'] ?? 'unknown'));
    } else {
        error("Ошибка отправки");
        error("  Причина: " . ($sendResult['error'] ?? 'unknown'));
        
        // Детальная диагностика
        printSubHeader("🔧 ДИАГНОСТИКА ОТПРАВКИ");
        
        $sessionInfo = $madelineService->getSessionInfo($domain, $sessionId);
        info("Статус сессии: " . ($sessionInfo['status'] ?? 'unknown'));
        
        $sessionFile = $madelineService->getSessionFile($domain, $sessionId);
        info("Файл сессии: {$sessionFile}");
        info("Существует: " . (file_exists($sessionFile) ? 'да' : 'нет'));
        
        if (file_exists($sessionFile)) {
            info("Размер: " . filesize($sessionFile) . " байт");
        }
    }
}

// 10. Тест отправки медиа (опционально)
printSubHeader("10. ДОПОЛНИТЕЛЬНЫЕ ТЕСТЫ");

$mediaTest = prompt("Тестировать отправку медиа? (y/n)", 'n');

if (strtolower($mediaTest) === 'y') {
    $mediaType = prompt("Тип медиа (photo/document/voice/video)", 'photo');
    $mediaUrl = prompt("URL файла");
    
    if ($mediaUrl) {
        info("Отправка {$mediaType}...");
        
        switch ($mediaType) {
            case 'photo':
                $result = $messenger->sendPhoto($chatId, $mediaUrl, "📸 Тестовое фото");
                break;
            case 'document':
                $result = $messenger->sendDocument($chatId, $mediaUrl, "📄 Тестовый документ");
                break;
            case 'voice':
                $result = $messenger->sendVoice($chatId, $mediaUrl);
                break;
            case 'video':
                $result = $messenger->sendVideo($chatId, $mediaUrl, "🎥 Тестовое видео");
                break;
            default:
                error("Неизвестный тип медиа");
                $result = ['success' => false];
        }
        
        if ($result['success']) {
            success("Медиа отправлено!");
        } else {
            error("Ошибка отправки медиа: " . ($result['error'] ?? 'unknown'));
        }
    }
}

// 11. Управление сессией
printSubHeader("11. УПРАВЛЕНИЕ СЕССИЕЙ");

$manageSession = prompt("Изменить настройки сессии? (y/n)", 'n');

if (strtolower($manageSession) === 'y') {
    echo "\n1. Изменить имя\n";
    echo "2. Удалить сессию\n";
    echo "3. Пропустить\n\n";
    
    $choice = prompt("Выберите действие (1-3)", '3');
    
    switch ($choice) {
        case '1':
            $newName = prompt("Новое имя сессии");
            if ($newName) {
                $updated = $madelineService->updateSessionName($domain, $sessionId, $newName);
                if ($updated) {
                    success("Имя обновлено");
                    $sessionName = $newName;
                } else {
                    error("Не удалось обновить имя");
                }
            }
            break;
            
        case '2':
            $confirm = prompt("Удалить сессию '{$sessionName}'? (yes/no)", 'no');
            if ($confirm === 'yes') {
                $deleted = $madelineService->deleteSession($domain, $sessionId);
                if ($deleted) {
                    success("Сессия удалена");
                } else {
                    error("Не удалось удалить сессию");
                }
            }
            break;
    }
}

// 12. Итоговая статистика
printSubHeader("12. ИТОГОВАЯ СТАТИСТИКА");

// Собираем все сессии
$allSessions = $madelineService->getDomainSessions($domain);
$authorizedSessions = array_filter($allSessions, fn($s) => $s['status'] === 'authorized');

echo colorize("📊 СТАТИСТИКА СЕССИЙ:", 'cyan') . "\n";
echo "  • Всего сессий: " . colorize(count($allSessions), 'white') . "\n";
echo "  • Авторизовано: " . colorize(count($authorizedSessions), 'green') . "\n";
echo "  • Ожидают авторизации: " . colorize(count($allSessions) - count($authorizedSessions), 'yellow') . "\n";
echo "\n";

echo colorize("📋 ТЕКУЩАЯ СЕССИЯ:", 'cyan') . "\n";
echo "  • ID: " . colorize($sessionId, 'white') . "\n";
echo "  • Имя: " . colorize($sessionName, 'white') . "\n";
echo "  • Статус: " . colorize($authorized ? 'authorized' : 'unknown', $authorized ? 'green' : 'yellow') . "\n";

if (isset($accountData)) {
    echo "  • Аккаунт: " . colorize($accountData['first_name'] . ' (@' . $accountData['username'] . ')', 'white') . "\n";
}

$sessionFile = $madelineService->getSessionFile($domain, $sessionId);
echo "  • Файл: " . (file_exists($sessionFile) ? colorize("✅ существует", 'green') : colorize("❌ отсутствует", 'red')) . "\n";

if (file_exists($sessionFile)) {
    echo "  • Размер: " . colorize(number_format(filesize($sessionFile)) . ' байт', 'white') . "\n";
}

// 13. Финальный отчет
printHeader("📈 ФИНАЛЬНЫЙ ОТЧЕТ");

$tests = [
    ['Подключение к БД', true],
    ['Поиск домена', (bool)$tokenData],
    ['Создание сессии', $createResult['success'] ?? false],
    ['Интерактивная авторизация', $authResult['success'] ?? false],
    ['Получение данных аккаунта', isset($accountData) && !empty($accountData)],
    ['Messenger API', $messengerInfo['success'] ?? false],
    ['Активность сессии', $messenger->isSessionActive()],
    ['Отправка сообщения', $sendResult['success'] ?? false],
];

$passed = 0;
$total = count($tests);

foreach ($tests as [$name, $result]) {
    $status = $result ? colorize('PASS', 'green') : colorize('FAIL', 'red');
    $icon = $result ? '✅' : '❌';
    echo "{$icon} " . str_pad($name, 30) . " [{$status}]\n";
    if ($result) $passed++;
}

$percentage = round(($passed / $total) * 100, 1);

echo "\n";
echo colorize(str_repeat("=", 70), 'cyan') . "\n";
echo colorize("Результат: {$passed}/{$total} тестов пройдено ({$percentage}%)", 'white') . "\n";

if ($percentage >= 80) {
    echo colorize("🏆 ОТЛИЧНО! QR авторизация работает корректно!", 'green') . "\n";
} elseif ($percentage >= 60) {
    echo colorize("⚠️  ХОРОШО. Есть незначительные проблемы.", 'yellow') . "\n";
} else {
    echo colorize("❌ ТРЕБУЕТСЯ ДОРАБОТКА. Критические ошибки.", 'red') . "\n";
}

echo colorize(str_repeat("=", 70), 'cyan') . "\n";

// Рекомендации
if ($percentage < 100) {
    echo "\n" . colorize("💡 РЕКОМЕНДАЦИИ:", 'yellow') . "\n";
    
    if (!$tokenData) {
        echo "  • Установите интеграцию Bitrix24 для домена\n";
    }
    if (!($authResult['success'] ?? false)) {
        echo "  • Проверьте процесс интерактивной авторизации\n";
        echo "  • Убедитесь, что api_id и api_hash корректны\n";
    }
    if (!isset($accountData) || empty($accountData)) {
        echo "  • Авторизация не завершена или данные не получены\n";
    }
    if (!($sendResult['success'] ?? false)) {
        echo "  • Проверьте права доступа к файлам сессий\n";
        echo "  • Проверьте сетевые настройки и прокси\n";
    }
    if (!$messenger->isSessionActive()) {
        echo "  • Перезапустите авторизацию\n";
        echo "  • Проверьте логи для деталей\n";
    }
}

echo "\n" . colorize("🏁 ТЕСТИРОВАНИЕ ЗАВЕРШЕНО", 'cyan') . "\n\n";