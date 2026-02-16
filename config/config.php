<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// Загружаем переменные окружения
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

return [
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'name' => $_ENV['DB_NAME'] ?? '',
        'user' => $_ENV['DB_USER'] ?? '',
        'password' => $_ENV['DB_PASS'] ?? '',
        'charset' => 'utf8mb4',
    ],

    'bitrix' => [
        'client_id' => $_ENV['BITRIX_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['BITRIX_CLIENT_SECRET'] ?? '',
        'oauth_url' => 'https://oauth.bitrix24.tech/oauth/token/',
        'webhook_url' => $_ENV['BITRIX_WEBHOOK_URL'] ?? '',
    ],

    'telegram' => [
        'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
        'api_url' => 'https://api.telegram.org/bot',
        'api_id' => (int)($_ENV['TELEGRAM_API_ID'] ?? 6),
        'api_hash' => $_ENV['TELEGRAM_API_HASH'] ?? 'eb06d4abfb49dc3eeb1aeb98ae0f581e',
    ],

    'max' => [
        'api_url' => $_ENV['MAX_API_URL'] ?? 'https://platform-api.max.ru',
        'api_key' => $_ENV['MAX_API_KEY'] ?? '',
    ],

    'app' => [
        'name' => 'Bitrix-Telegram Integration',
        'url' => $_ENV['APP_URL'] ?? '',
        'timezone' => 'UTC',
        'debug' => $_ENV['APP_DEBUG'] === 'true',
    ],

    'logging' => [
        'enabled' => $_ENV['LOGGING_ENABLED'] === 'true',
        'path' => __DIR__ . '/../logs',
        'level' => $_ENV['LOG_LEVEL'] ?? 'info',
    ],
];