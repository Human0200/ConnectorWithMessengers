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
        $config,
        $pdo
    );

    // Активация из Bitrix24
    if (!empty($_REQUEST['PLACEMENT']) && $_REQUEST['PLACEMENT'] === 'SETTING_CONNECTOR') {
        // Проверяем наличие токена в сессии или в запросе
        session_start();
        $apiToken = $_REQUEST['api_token'] ?? $_SESSION['pending_api_token'] ?? null;

        if (empty($apiToken)) {
            // Показываем форму ввода токена
            $domain   = $_REQUEST['DOMAIN'] ?? '';
            $options  = $_REQUEST['PLACEMENT_OPTIONS'] ?? '';
            renderTokenForm($domain, $options);
        } else {
            // Токен есть — активируем
            unset($_SESSION['pending_api_token']);
            $installController->activate($_REQUEST, $apiToken);
        }
    } else {
        // Прямой заход на страницу — показываем информационную страницу
        renderInfoPage();
    }
} catch (\Exception $e) {
    $logger->logException($e, 'Activation failed');
    http_response_code(500);
    renderError($e->getMessage(), $config['app']['debug'] ?? false, $e);
}

// ──────────────────────────────────────────────────────────────
//  Рендер: форма ввода токена
// ──────────────────────────────────────────────────────────────
function renderTokenForm(string $domain, string $placementOptions): void
{
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подключение коннектора</title>
    <style>
        *, ::before, ::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
            padding: 36px 40px;
            max-width: 480px;
            width: 100%;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }
        .logo-mark {
            width: 36px; height: 36px;
            background: #18181b;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 18px;
        }
        .logo-name { font-size: 17px; font-weight: 600; color: #18181b; }
        h2 { font-size: 20px; font-weight: 600; color: #18181b; margin-bottom: 6px; }
        .sub { font-size: 13px; color: #71717a; margin-bottom: 24px; line-height: 1.5; }
        .steps {
            background: #eff6ff;
            border: 1px solid rgba(37,99,235,.15);
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 22px;
            font-size: 13px;
            color: #3f3f46;
            line-height: 1.7;
        }
        .steps ol { padding-left: 18px; }
        .steps li { margin: 2px 0; }
        .steps a { color: #2563eb; text-decoration: none; font-weight: 500; }
        .steps a:hover { text-decoration: underline; }
        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #3f3f46;
            margin-bottom: 6px;
            letter-spacing: .3px;
            text-transform: uppercase;
        }
        input[type=text] {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #e4e4e7;
            border-radius: 8px;
            font-size: 13.5px;
            color: #18181b;
            font-family: 'SF Mono', 'Fira Code', monospace;
            outline: none;
            transition: border .15s, box-shadow .15s;
        }
        input[type=text]:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.10);
        }
        input[type=text]::placeholder { color: #a1a1aa; font-family: 'Segoe UI', system-ui, sans-serif; }
        .err-msg {
            font-size: 12px;
            color: #dc2626;
            margin-top: 5px;
            display: none;
        }
        .btn {
            margin-top: 18px;
            width: 100%;
            padding: 11px;
            background: #18181b;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .13s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn:hover { background: #27272a; }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .spin {
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: rot .6s linear infinite;
            display: none;
        }
        @keyframes rot { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <div class="logo-mark">⚡</div>
        <span class="logo-name">ConnectHub</span>
    </div>

    <h2>Подключение к Bitrix24</h2>
    <p class="sub">Для активации коннектора введите API-токен из вашего личного кабинета ConnectHub.</p>

    <div class="steps">
        <ol>
            <li>Откройте <a href="/" target="_blank">личный кабинет ConnectHub</a></li>
            <li>Перейдите на вкладку «Обзор»</li>
            <li>Скопируйте ваш API-токен</li>
            <li>Вставьте его в поле ниже</li>
        </ol>
    </div>

    <form method="POST" action="" id="activateForm">
        <input type="hidden" name="PLACEMENT" value="SETTING_CONNECTOR">
        <input type="hidden" name="DOMAIN" value="<?= htmlspecialchars($domain) ?>">
        <input type="hidden" name="PLACEMENT_OPTIONS" value="<?= htmlspecialchars($placementOptions) ?>">

        <label for="api_token">API-токен</label>
        <input
            type="text"
            id="api_token"
            name="api_token"
            placeholder="btg_••••••••••••••••••••••••••••••••"
            autocomplete="off"
            spellcheck="false"
        >
        <div class="err-msg" id="errMsg">Введите корректный API-токен</div>

        <button type="submit" class="btn" id="submitBtn">
            <span class="spin" id="spin"></span>
            <span id="btnTxt">Подключить</span>
        </button>
    </form>
</div>
<script>
document.getElementById('activateForm').addEventListener('submit', function(e) {
    const input = document.getElementById('api_token');
    const err   = document.getElementById('errMsg');
    const token = input.value.trim();
    if (!token || token.length < 10) {
        e.preventDefault();
        input.style.borderColor = '#dc2626';
        err.style.display = 'block';
        return;
    }
    input.style.borderColor = '';
    err.style.display = 'none';
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('spin').style.display = 'inline-block';
    document.getElementById('btnTxt').textContent = 'Подключение...';
});
document.getElementById('api_token').addEventListener('input', function() {
    this.style.borderColor = '';
    document.getElementById('errMsg').style.display = 'none';
});
</script>
</body>
</html>
    <?php
}

// ──────────────────────────────────────────────────────────────
//  Рендер: информационная страница (прямой заход)
// ──────────────────────────────────────────────────────────────
function renderInfoPage(): void
{
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Активация коннектора</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f5f7fa; margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.1); padding: 36px; max-width: 560px; width: 100%; }
        h1 { font-size: 22px; color: #18181b; margin-bottom: 6px; }
        .sub { color: #71717a; font-size: 13px; margin-bottom: 24px; }
        .info { background: #eff6ff; border-left: 4px solid #2563eb; padding: 14px 16px; margin: 16px 0; border-radius: 4px; font-size: 13.5px; color: #3f3f46; line-height: 1.7; }
        .warn { background: #fffbeb; border-left: 4px solid #d97706; padding: 14px 16px; margin: 16px 0; border-radius: 4px; font-size: 13.5px; color: #3f3f46; }
        ol, ul { margin: 8px 0; padding-left: 20px; }
        li { margin: 5px 0; }
        code { background: #f4f4f5; padding: 2px 6px; border-radius: 3px; font-family: monospace; font-size: 12.5px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Активация коннектора</h1>
    <p class="sub">Настройка интеграции с открытыми линиями Bitrix24</p>
    <div class="info">
        <strong>ℹ️ Инструкция по активации:</strong>
        <ol>
            <li>Перейдите в Bitrix24 → CRM → Открытые линии</li>
            <li>Выберите открытую линию или создайте новую</li>
            <li>Нажмите «Подключить мессенджер»</li>
            <li>Найдите в списке «ConnectHub»</li>
            <li>Введите ваш API-токен из личного кабинета</li>
        </ol>
    </div>
    <div class="warn">
        <strong>⚠️ Важно:</strong> Эта страница вызывается автоматически из Bitrix24 при настройке коннектора.
    </div>
    <div class="info">
        <strong>📝 Параметры:</strong>
        <ul>
            <li><code>PLACEMENT</code> — должен быть <code>SETTING_CONNECTOR</code></li>
            <li><code>PLACEMENT_OPTIONS</code> — JSON с параметрами линии</li>
            <li><code>DOMAIN</code> — домен Bitrix24</li>
        </ul>
    </div>
</div>
</body>
</html>
    <?php
}

// ──────────────────────────────────────────────────────────────
//  Рендер: ошибка
// ──────────────────────────────────────────────────────────────
function renderError(string $message, bool $debug = false, \Throwable $e = null): void
{
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Ошибка активации</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card { background: #fff; border-radius: 12px; padding: 36px; max-width: 500px; box-shadow: 0 4px 20px rgba(0,0,0,.1); text-align: center; }
        .ico { font-size: 44px; margin-bottom: 16px; }
        h1 { color: #dc2626; font-size: 20px; margin-bottom: 16px; }
        .msg { background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 14px; border-radius: 4px; text-align: left; font-size: 13px; color: #3f3f46; margin-bottom: 12px; }
        .detail { background: #f4f4f5; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 12px; text-align: left; overflow-x: auto; }
    </style>
</head>
<body>
<div class="card">
    <div class="ico">❌</div>
    <h1>Ошибка активации</h1>
    <div class="msg"><?= htmlspecialchars($message) ?></div>
    <?php if ($debug && $e): ?>
    <div class="detail">
        <strong>Файл:</strong> <?= htmlspecialchars($e->getFile()) ?><br>
        <strong>Строка:</strong> <?= $e->getLine() ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
    <?php
}