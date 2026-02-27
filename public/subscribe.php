<?
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Services\MaxService;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Helpers\Logger;


// Загружаем конфигурацию
$config = require __DIR__ . '/../config/config.php';

// Инициализируем зависимости
$database = Database::getInstance($config['database']);
$pdo = $database->getConnection();

$logger = new Logger($config['logging']);
$tokenRepository = new TokenRepository($pdo);

$data = json_decode(file_get_contents('php://input'), true) ?: [];

$MaxService = new MaxService($tokenRepository, $logger, $config['max']['api_url']);
$MaxService ->setWebhook("https://bitrix-connector.lead-space.ru/ConnectorHub/public/webhook.php", $data['domain']);


return json_encode([
    'status' => 'success',
    'domain' => $data,
]);


