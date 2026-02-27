<?php
require_once './crest.php';
require_once __DIR__ . '/../vendor/autoload.php';
use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;

// Загружаем конфигурацию
$config = require __DIR__ . '/../config/config.php';

// Инициализируем зависимости
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();

$logger = new Logger($config['logging']);
$tokenRepository = new TokenRepository($pdo);


$result = CRest::installApp();
if ($result['rest_only'] === false) { ?>
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
                height: 100vh;
                color: #333;
            }

            .container {
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                padding: 30px;
                width: 400px;
                text-align: center;
            }

            .icon {
                font-size: 48px;
                margin-bottom: 20px;
                color:
                    <?= $result['install'] ? '#2fc06e' : '#ff5752' ?>
                ;
            }

            h1 {
                font-size: 24px;
                margin-bottom: 15px;
                color: #424956;
            }

            p {
                font-size: 16px;
                margin-bottom: 25px;
                line-height: 1.5;
            }

            .btn {
                background-color: #2f81b7;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 4px;
                font-size: 16px;
                cursor: pointer;
                transition: background-color 0.3s;
                font-weight: 600;
            }

            .btn:hover {
                background-color: #236a9a;
            }

            .hidden {
                display: none;
            }
        </style>
    </head>

    <body>
        <div class="container">
            <?php if ($result['install'] == true) { ?>
                <div class="icon">✓</div>
                <h1>Установка завершена</h1>
                <p>Приложение успешно установлено в ваш Битрикс24. Нажмите "Продолжить", чтобы начать работу.</p>
                <button id="continueBtn" class="btn">Продолжить</button>

                <script>
                    BX24.init(function () {
                        document.getElementById('continueBtn').addEventListener('click', function () {
                            BX24.installFinish();
                        });
                    });
                </script>
            <?php } else { ?>
                <div class="icon">✗</div>
                <h1>Ошибка установки</h1>
                <p>При установке приложения произошла ошибка. Пожалуйста, попробуйте еще раз или обратитесь в поддержку.</p>
            <?php } ?>
        </div>
    </body>

    </html>
<?php }

$install_handler = ($_SERVER['HTTPS'] === 'on' || $_SERVER['SERVER_PORT'] === '443' ? 'https' : 'http') . '://'
    . $_SERVER['SERVER_NAME']
    . (in_array($_SERVER['SERVER_PORT'], ['80', '443'], true) ? '' : ':' . $_SERVER['SERVER_PORT'])
    . str_replace($_SERVER['DOCUMENT_ROOT'], '', realpath(__DIR__ . '/install.php'));

$uninstall_handler = ($_SERVER['HTTPS'] === 'on' || $_SERVER['SERVER_PORT'] === '443' ? 'https' : 'http') . '://'
    . $_SERVER['SERVER_NAME']
    . (in_array($_SERVER['SERVER_PORT'], ['80', '443'], true) ? '' : ':' . $_SERVER['SERVER_PORT'])
    . str_replace($_SERVER['DOCUMENT_ROOT'], '', realpath(__DIR__ . '/uninstall_handler.php'));



CRest::call(
    'event.bind',
    [
        'event' => 'ONAPPINSTALL',
        'handler' => $install_handler,
    ]
);

CRest::call(
    'event.bind',
    [
        'event' => 'ONAPPUPDATE',
        'handler' => $install_handler,
    ]
);

CRest::call(
    'event.bind',
    [
        'event' => 'ONAPPUNINSTALL',
        'handler' => $uninstall_handler,
    ]
);

// URL обработчика вебхуков
$handlerUrl = 'https://bitrix-connector.lead-space.ru/ConnectorHub/public/webhook.php'; 
$registrationUrl = 'https://bitrix-connector.lead-space.ru/ConnectorHub/public/activate.php';
if($_REQUEST['DOMAIN']){
    
}else{
    die('Domain not found');
}
$connector_id = $tokenRepository->getConnectorId($_REQUEST['DOMAIN'] ?? null, 'max');

// Регистрируем коннектор
$result = CRest::call(
    'imconnector.register',
    [
        'ID' => $connector_id,
        'NAME' => 'Интеграция с МАКС',
        'ICON' => [
            'DATA_IMAGE' => 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20version%3D%221.1%22%20id%3D%22Layer_1%22%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20x%3D%220px%22%20y%3D%220px%22%0A%09%20viewBox%3D%220%200%2070%2071%22%20style%3D%22enable-background%3Anew%200%200%2070%2071%3B%22%20xml%3Aspace%3D%22preserve%22%3E%0A%3Cpath%20fill%3D%22%230C99BA%22%20class%3D%22st0%22%20d%3D%22M34.7%2C64c-11.6%2C0-22-7.1-26.3-17.8C4%2C35.4%2C6.4%2C23%2C14.5%2C14.7c8.1-8.2%2C20.4-10.7%2C31-6.2%0A%09c12.5%2C5.4%2C19.6%2C18.8%2C17%2C32.2C60%2C54%2C48.3%2C63.8%2C34.7%2C64L34.7%2C64z%20M27.8%2C29c0.8-0.9%2C0.8-2.3%2C0-3.2l-1-1.2h19.3c1-0.1%2C1.7-0.9%2C1.7-1.8%0A%09v-0.9c0-1-0.7-1.8-1.7-1.8H26.8l1.1-1.2c0.8-0.9%2C0.8-2.3%2C0-3.2c-0.4-0.4-0.9-0.7-1.5-0.7s-1.1%2C0.2-1.5%2C0.7l-4.6%2C5.1%0A%09c-0.8%2C0.9-0.8%2C2.3%2C0%2C3.2l4.6%2C5.1c0.4%2C0.4%2C0.9%2C0.7%2C1.5%2C0.7C26.9%2C29.6%2C27.4%2C29.4%2C27.8%2C29L27.8%2C29z%20M44%2C41c-0.5-0.6-1.3-0.8-2-0.6%0A%09c-0.7%2C0.2-1.3%2C0.9-1.5%2C1.6c-0.2%2C0.8%2C0%2C1.6%2C0.5%2C2.2l1%2C1.2H22.8c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h19.3l-1%2C1.2%0A%09c-0.5%2C0.6-0.7%2C1.4-0.5%2C2.2c0.2%2C0.8%2C0.7%2C1.4%2C1.5%2C1.6c0.7%2C0.2%2C1.5%2C0%2C2-0.6l4.6-5.1c0.8-0.9%2C0.8-2.3%2C0-3.2L44%2C41z%20M23.5%2C32.8%0A%09c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h23.4c1-0.1%2C1.7-0.9%2C1.7-1.8v-0.9c0-1-0.7-1.8-1.7-1.9L23.5%2C32.8L23.5%2C32.8z%22/%3E%0A%3C/svg%3E%0A',
            'COLOR' => '#a6ffa3',
            'SIZE' => '100%',
            'POSITION' => 'center',
        ],
        'ICON_DISABLED' => [
            'DATA_IMAGE' => 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20version%3D%221.1%22%20id%3D%22Layer_1%22%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20x%3D%220px%22%20y%3D%220px%22%0A%09%20viewBox%3D%220%200%2070%2071%22%20style%3D%22enable-background%3Anew%200%200%2070%2071%3B%22%20xml%3Aspace%3D%22preserve%22%3E%0A%3Cpath%20fill%3D%22%230C99BA%22%20class%3D%22st0%22%20d%3D%22M34.7%2C64c-11.6%2C0-22-7.1-26.3-17.8C4%2C35.4%2C6.4%2C23%2C14.5%2C14.7c8.1-8.2%2C20.4-10.7%2C31-6.2%0A%09c12.5%2C5.4%2C19.6%2C18.8%2C17%2C32.2C60%2C54%2C48.3%2C63.8%2C34.7%2C64L34.7%2C64z%20M27.8%2C29c0.8-0.9%2C0.8-2.3%2C0-3.2l-1-1.2h19.3c1-0.1%2C1.7-0.9%2C1.7-1.8%0A%09v-0.9c0-1-0.7-1.8-1.7-1.8H26.8l1.1-1.2c0.8-0.9%2C0.8-2.3%2C0-3.2c-0.4-0.4-0.9-0.7-1.5-0.7s-1.1%2C0.2-1.5%2C0.7l-4.6%2C5.1%0A%09c-0.8%2C0.9-0.8%2C2.3%2C0%2C3.2l4.6%2C5.1c0.4%2C0.4%2C0.9%2C0.7%2C1.5%2C0.7C26.9%2C29.6%2C27.4%2C29.4%2C27.8%2C29L27.8%2C29z%20M44%2C41c-0.5-0.6-1.3-0.8-2-0.6%0A%09c-0.7%2C0.2-1.3%2C0.9-1.5%2C1.6c-0.2%2C0.8%2C0%2C1.6%2C0.5%2C2.2l1%2C1.2H22.8c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h19.3l-1%2C1.2%0A%09c-0.5%2C0.6-0.7%2C1.4-0.5%2C2.2c0.2%2C0.8%2C0.7%2C1.4%2C1.5%2C1.6c0.7%2C0.2%2C1.5%2C0%2C2-0.6l4.6-5.1c0.8-0.9%2C0.8-2.3%2C0-3.2L44%2C41z%20M23.5%2C32.8%0A%09c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h23.4c1-0.1%2C1.7-0.9%2C1.7-1.8v-0.9c0-1-0.7-1.8-1.7-1.9L23.5%2C32.8L23.5%2C32.8z%22/%3E%0A%3C/svg%3E%0A',
            'SIZE' => '100%',
            'POSITION' => 'center',
            'COLOR' => '#ffb3a3',
        ],
        'PLACEMENT_HANDLER' => $registrationUrl,
    ]
);

if (!empty($result['result'])) {
    // Привязываем событие для обработки сообщений
    $resultEvent = CRest::call(
        'event.bind',
        [
            'event' => 'OnImConnectorMessageAdd',
            'handler' => $handlerUrl,
        ]
    );
    
    if (!empty($resultEvent['result'])) {
       // echo 'successfully';
    } else {
        echo 'Error binding event: ' . print_r($resultEvent, true);
    }
} else {
    echo 'Error registering connector: ' . print_r($result, true);
}
