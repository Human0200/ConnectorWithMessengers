<?php

declare(strict_types=1);

namespace BitrixTelegram\Controllers;

use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Middleware\AuthMiddleware;
use BitrixTelegram\Helpers\Logger;

class ProfileController
{
    private const ALLOWED_TYPES = ['max', 'telegram_bot', 'telegram_user'];

    public function __construct(
        private ProfileRepository $profileRepository,
        private TokenRepository   $tokenRepository,
        private AuthMiddleware    $authMiddleware,
        private Logger            $logger,
        private array             $config = []   // ← ДОБАВЛЕНО: нужен для URL webhook
    ) {}

    /**
     * GET /profiles
     */
    public function index(): array
    {
        $user     = $this->authMiddleware->require();
        $profiles = $this->profileRepository->getByUser($user['id']);
        $stats    = $this->profileRepository->getStats($user['id']);

        foreach ($profiles as &$profile) {
            $profile['connections'] = $this->profileRepository->getConnections($profile['id'], $user['id']);
            unset($profile['token']);
        }

        return [
            'success'  => true,
            'profiles' => $profiles,
            'stats'    => $stats,
        ];
    }

    /**
     * POST /profiles
     * Body: { messenger_type, name, token? }
     *
     * ← ИЗМЕНЕНО для telegram_bot:
     *   1. Проверяет токен через getMe
     *   2. Создаёт профиль
     *   3. Регистрирует webhook: /public/webhook.php?bot_token=TOKEN
     */
    public function create(array $data): array
    {
        $user = $this->authMiddleware->require();

        $type  = trim($data['messenger_type'] ?? '');
        $name  = trim($data['name'] ?? '');
        $token = trim($data['token'] ?? '') ?: null;

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return ['success' => false, 'errors' => ['messenger_type' => 'Неподдерживаемый тип: ' . $type]];
        }

        if (empty($name)) {
            return ['success' => false, 'errors' => ['name' => 'Введите название профиля']];
        }

        if (in_array($type, ['max', 'telegram_bot'], true) && empty($token)) {
            return ['success' => false, 'errors' => ['token' => 'Токен обязателен для этого типа']];
        }

        // ← ДОБАВЛЕНО: для max — проверяем токен через GET /me
        $extra = [];
        if ($type === 'max') {
            $botInfo = $this->callMaxApi($token, 'me', [], 'GET');
            if (empty($botInfo['success'])) {
                return ['success' => false, 'errors' => [
                    'token' => 'Ошибка Max API: ' . ($botInfo['error'] ?? 'Неверный токен'),
                ]];
            }
            $extra = [
                'bot_id'       => $botInfo['data']['id']         ?? null,
                'bot_username' => $botInfo['data']['username']    ?? null,
                'bot_name'     => $botInfo['data']['name']        ?? null,
            ];
        }

        // ← ДОБАВЛЕНО: для telegram_bot — проверяем токен и собираем данные бота
        if ($type === 'telegram_bot') {
            $botInfo = $this->callTelegramApi($token, 'getMe');
            if (empty($botInfo['ok'])) {
                return ['success' => false, 'errors' => [
                    'token' => 'Ошибка Telegram API: ' . ($botInfo['description'] ?? 'Неверный токен'),
                ]];
            }
            $extra = [
                'bot_id'       => $botInfo['result']['id']        ?? null,
                'bot_username' => $botInfo['result']['username']   ?? null,
                'bot_name'     => $botInfo['result']['first_name'] ?? null,
            ];
        }

        $profile = $this->profileRepository->create($user['id'], $type, $name, $token, $extra);

        if (!$profile) {
            return ['success' => false, 'errors' => ['general' => 'Ошибка создания профиля']];
        }

        // ← ДОБАВЛЕНО: для max — регистрируем webhook сразу после создания профиля
        if ($type === 'max') {
            $webhookResult = $this->registerMaxWebhook($token);
            if (!($webhookResult['success'] ?? false)) {
                $profile['webhook_warning'] = 'Профиль создан, но webhook не установлен: '
                    . ($webhookResult['error'] ?? 'неизвестная ошибка');
            } else {
                $profile['webhook_set'] = true;
            }
            $this->logger->info('max webhook registered', [
                'profile_id' => $profile['id'],
                'ok'         => $webhookResult['success'] ?? false,
            ]);
        }

        // ← ДОБАВЛЕНО: регистрируем webhook сразу после создания профиля
        if ($type === 'telegram_bot') {
            $webhookResult = $this->registerBotWebhook($token);
            if (!($webhookResult['ok'] ?? false)) {
                $profile['webhook_warning'] = 'Профиль создан, но webhook не установлен: '
                    . ($webhookResult['description'] ?? 'неизвестная ошибка');
            } else {
                $profile['webhook_set'] = true;
            }
            $this->logger->info('telegram_bot webhook registered', [
                'profile_id' => $profile['id'],
                'ok'         => $webhookResult['ok'] ?? false,
            ]);
        }

        $this->logger->info('Profile created', [
            'user_id'        => $user['id'],
            'profile_id'     => $profile['id'],
            'messenger_type' => $type,
        ]);

        return ['success' => true, 'profile' => $profile];
    }

    /**
     * GET /profiles/{id}
     */
    public function show(int $id): array
    {
        $user    = $this->authMiddleware->require();
        $profile = $this->profileRepository->findById($id, $user['id']);

        if (!$profile) {
            return ['success' => false, 'error' => 'Профиль не найден'];
        }

        $profile['connections'] = $this->profileRepository->getConnections($id, $user['id']);

        return ['success' => true, 'profile' => $profile];
    }

    /**
     * PATCH /profiles/{id}
     * Body: { name?, token?, is_active? }
     *
     * ← ИЗМЕНЕНО для telegram_bot: при смене токена переподписывает webhook
     */
    public function update(int $id, array $data): array
    {
        $user = $this->authMiddleware->require();

        $profile = $this->profileRepository->findById($id, $user['id']);
        if (!$profile) {
            return ['success' => false, 'error' => 'Профиль не найден'];
        }

        $fields = array_intersect_key($data, array_flip(['name', 'token', 'is_active']));

        if (isset($fields['name']) && empty(trim($fields['name']))) {
            return ['success' => false, 'errors' => ['name' => 'Название не может быть пустым']];
        }

        // ← ДОБАВЛЕНО: смена токена у max — нужно переподписать webhook
        if ($profile['messenger_type'] === 'max' && !empty($fields['token'])) {
            $newToken = trim($fields['token']);

            $botInfo = $this->callMaxApi($newToken, 'me', [], 'GET');
            if (empty($botInfo['success'])) {
                return ['success' => false, 'errors' => [
                    'token' => 'Ошибка Max API: ' . ($botInfo['error'] ?? 'Неверный токен'),
                ]];
            }

            // Снимаем старый webhook
            if (!empty($profile['token'])) {
                $this->deleteMaxWebhook($profile['token']);
            }

            // Ставим новый
            $this->registerMaxWebhook($newToken);

            $fields['extra'] = [
                'bot_id'       => $botInfo['data']['id']         ?? null,
                'bot_username' => $botInfo['data']['username']    ?? null,
                'bot_name'     => $botInfo['data']['name']        ?? null,
            ];
        }

        // ← ДОБАВЛЕНО: смена токена у telegram_bot — нужно переподписать webhook
        if ($profile['messenger_type'] === 'telegram_bot' && !empty($fields['token'])) {
            $newToken = trim($fields['token']);

            $botInfo = $this->callTelegramApi($newToken, 'getMe');
            if (empty($botInfo['ok'])) {
                return ['success' => false, 'errors' => [
                    'token' => 'Ошибка Telegram API: ' . ($botInfo['description'] ?? 'Неверный токен'),
                ]];
            }

            // Снимаем старый webhook
            if (!empty($profile['token'])) {
                $this->callTelegramApi($profile['token'], 'deleteWebhook');
            }

            // Ставим новый
            $this->registerBotWebhook($newToken);

            $fields['extra'] = [
                'bot_id'       => $botInfo['result']['id']        ?? null,
                'bot_username' => $botInfo['result']['username']   ?? null,
                'bot_name'     => $botInfo['result']['first_name'] ?? null,
            ];
        }

        $this->profileRepository->update($id, $user['id'], $fields);
        $updated = $this->profileRepository->findById($id, $user['id']);

        $this->logger->info('Profile updated', ['user_id' => $user['id'], 'profile_id' => $id]);

        return ['success' => true, 'profile' => $updated];
    }

    /**
     * DELETE /profiles/{id}
     *
     * ← ИЗМЕНЕНО для telegram_bot: снимает webhook перед удалением
     */
    public function delete(int $id): array
    {
        $user    = $this->authMiddleware->require();
        $profile = $this->profileRepository->findById($id, $user['id']);

        if (!$profile) {
            return ['success' => false, 'error' => 'Профиль не найден'];
        }

        // ← ДОБАВЛЕНО
        if ($profile['messenger_type'] === 'max' && !empty($profile['token'])) {
            $this->deleteMaxWebhook($profile['token']);
            $this->logger->info('max webhook deleted', ['profile_id' => $id]);
        }

        // ← ДОБАВЛЕНО
        if ($profile['messenger_type'] === 'telegram_bot' && !empty($profile['token'])) {
            $this->callTelegramApi($profile['token'], 'deleteWebhook', ['drop_pending_updates' => true]);
            $this->logger->info('telegram_bot webhook deleted', ['profile_id' => $id]);
        }

        $this->profileRepository->delete($id, $user['id']);

        $this->logger->info('Profile deleted', ['user_id' => $user['id'], 'profile_id' => $id]);

        return ['success' => true];
    }

    /**
     * POST /profiles/{id}/connect
     */
    public function connect(int $profileId, array $data): array
    {
        $user    = $this->authMiddleware->require();
        $profile = $this->profileRepository->findById($profileId, $user['id']);

        if (!$profile) {
            return ['success' => false, 'error' => 'Профиль не найден'];
        }

        $domain     = trim($data['domain'] ?? '');
        $openlineId = isset($data['openline_id']) ? (int)$data['openline_id'] : null;

        if (empty($domain)) {
            return ['success' => false, 'errors' => ['domain' => 'Введите домен']];
        }

        $bitrixData = $this->tokenRepository->findByDomain($domain);
        if (!$bitrixData) {
            return ['success' => false, 'errors' => ['domain' => 'Домен не найден. Установите приложение в Bitrix24.']];
        }

        if (!empty($bitrixData['user_id']) && (int)$bitrixData['user_id'] !== $user['id']) {
            return ['success' => false, 'errors' => ['domain' => 'Этот домен привязан к другому аккаунту']];
        }

        $connectorId = $this->tokenRepository->getConnectorId($domain, $profile['messenger_type']);
        $this->logger->info('Connecting profile to domain', [
            'user_id'        => $user['id'],
            'profile_id'     => $profileId,
            'domain'         => $domain,
            'connector_id'   => $connectorId,
            'openline_id'    => $openlineId,
        ]);
        $this->profileRepository->saveConnection(
            $user['id'],
            $profileId,
            $domain,
            $connectorId,
            $openlineId
        );

        if (empty($bitrixData['user_id'])) {
            $this->tokenRepository->linkUser($domain, $user['id']);
        }

        $this->logger->info('Profile connected to domain', [
            'user_id'    => $user['id'],
            'profile_id' => $profileId,
            'domain'     => $domain,
        ]);

        return [
            'success'      => true,
            'domain'       => $domain,
            'connector_id' => $connectorId,
            'openline_id'  => $openlineId,
        ];
    }

    /**
     * DELETE /profiles/{id}/connect
     */
    public function disconnect(int $profileId, array $data): array
    {
        $user   = $this->authMiddleware->require();
        $domain = trim($data['domain'] ?? '');

        if (empty($domain)) {
            return ['success' => false, 'errors' => ['domain' => 'Укажите домен']];
        }

        $this->profileRepository->deleteConnection($profileId, $user['id'], $domain);

        return ['success' => true];
    }

    /**
     * PATCH /profiles/{id}/openline
     */
    public function setOpenline(int $profileId, array $data): array
    {
        $user       = $this->authMiddleware->require();
        $domain     = trim($data['domain'] ?? '');
        $openlineId = (int)($data['openline_id'] ?? 0);

        if (empty($domain) || $openlineId <= 0) {
            return ['success' => false, 'errors' => ['general' => 'Укажите domain и openline_id']];
        }

        $profile = $this->profileRepository->findById($profileId, $user['id']);
        if (!$profile) {
            return ['success' => false, 'error' => 'Профиль не найден'];
        }

        $this->profileRepository->updateOpenline($profileId, $domain, $openlineId);

        $connectorId = $this->tokenRepository->getConnectorId($domain, $profile['messenger_type']);
        if ($connectorId) {
            $this->tokenRepository->updateLine($connectorId, $openlineId);
        }

        return ['success' => true, 'openline_id' => $openlineId];
    }

    /**
     * GET /domains
     */
    public function getDomains(): array
    {
        $user    = $this->authMiddleware->require();
        $domains = $this->tokenRepository->getDomainsByUser($user['id']);

        return ['success' => true, 'domains' => $domains];
    }

    // ─── ДОБАВЛЕНО: private методы для max webhook ───────────────

    /**
     * Регистрируем webhook для Max бота.
     * URL: https://yoursite.com/public/webhook.php?max_token=TOKEN
     */
    private function registerMaxWebhook(string $token): array
    {
        $appUrl     = rtrim($this->config['app']['url'] ?? '', '/');
        $webhookUrl = $appUrl . '/public/webhook.php?max_token=' . urlencode($token);

        return $this->callMaxApi($token, 'subscriptions', [
            'url'          => $webhookUrl,
            'update_types' => ['message_created', 'bot_started'],
            'secret'       => 'connector_hub',
        ]);
    }

    private function deleteMaxWebhook(string $token): array
    {
        $appUrl     = rtrim($this->config['app']['url'] ?? '', '/');
        $webhookUrl = $appUrl . '/public/webhook.php?max_token=' . urlencode($token);

        return $this->callMaxApi($token, 'subscriptions', ['url' => $webhookUrl], 'DELETE');
    }

    private function callMaxApi(string $token, string $endpoint, array $data = [], string $method = 'POST'): array
    {
        $baseUrl = 'https://platform-api.max.ru';
        $url     = $baseUrl . '/' . ltrim($endpoint, '/');

        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . trim($token),
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        } elseif ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            if (!empty($data)) {
                $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL: ' . $error];
        }

        $result = json_decode($response, true);

        return ($httpCode === 200)
            ? ['success' => true,  'data'  => $result]
            : ['success' => false, 'error' => ($result['message'] ?? 'HTTP ' . $httpCode), 'response' => $result];
    }

    // ─── ДОБАВЛЕНО: private методы для telegram_bot webhook ──────

    /**
     * Регистрируем webhook для бота.
     * URL: https://yoursite.com/public/webhook.php?bot_token=TOKEN
     *
     * В WebhookController читаем $_GET['bot_token'] и ищем профиль по нему.
     */
    private function registerBotWebhook(string $token): array
    {
        $appUrl     = rtrim($this->config['app']['url'] ?? '', '/');
        $webhookUrl = $appUrl . '/public/webhook.php?bot_token=' . urlencode($token);

        return $this->callTelegramApi($token, 'setWebhook', [
            'url'                  => $webhookUrl,
            'allowed_updates'      => ['message', 'edited_message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    private function callTelegramApi(string $token, string $method, array $params = []): array
    {
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'description' => 'cURL: ' . $error];
        }

        return json_decode($response, true) ?? ['ok' => false, 'description' => 'Invalid JSON'];
    }
}