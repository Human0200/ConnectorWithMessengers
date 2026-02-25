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
        private Logger            $logger
    ) {}

    /**
     * GET /profiles
     * Все профили текущего пользователя
     */
    public function index(): array
    {
        $user     = $this->authMiddleware->require();
        $profiles = $this->profileRepository->getByUser($user['id']);
        $stats    = $this->profileRepository->getStats($user['id']);

        // Для каждого профиля добавляем привязанные домены
        foreach ($profiles as &$profile) {
            $profile['connections'] = $this->profileRepository->getConnections($profile['id'], $user['id']);
            // Скрываем токен в листинге
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
     * Создать профиль мессенджера
     * Body: { messenger_type, name, token? }
     */
    public function create(array $data): array
    {
        $user = $this->authMiddleware->require();

        $type  = trim($data['messenger_type'] ?? '');
        $name  = trim($data['name'] ?? '');
        $token = trim($data['token'] ?? '') ?: null;

        // Валидация
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return ['success' => false, 'errors' => ['messenger_type' => 'Неподдерживаемый тип: ' . $type]];
        }

        if (empty($name)) {
            return ['success' => false, 'errors' => ['name' => 'Введите название профиля']];
        }

        // Для Max и telegram_bot токен обязателен
        if (in_array($type, ['max', 'telegram_bot'], true) && empty($token)) {
            return ['success' => false, 'errors' => ['token' => 'Токен обязателен для этого типа']];
        }

        $profile = $this->profileRepository->create($user['id'], $type, $name, $token);

        if (!$profile) {
            return ['success' => false, 'errors' => ['general' => 'Ошибка создания профиля']];
        }

        $this->logger->info('Profile created', [
            'user_id'        => $user['id'],
            'profile_id'     => $profile['id'],
            'messenger_type' => $type,
        ]);

        // Возвращаем токен только при создании
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

        $this->profileRepository->update($id, $user['id'], $fields);

        $updated = $this->profileRepository->findById($id, $user['id']);

        $this->logger->info('Profile updated', ['user_id' => $user['id'], 'profile_id' => $id]);

        return ['success' => true, 'profile' => $updated];
    }

    /**
     * DELETE /profiles/{id}
     */
    public function delete(int $id): array
    {
        $user    = $this->authMiddleware->require();
        $profile = $this->profileRepository->findById($id, $user['id']);

        if (!$profile) {
            return ['success' => false, 'error' => 'Профиль не найден'];
        }

        $this->profileRepository->delete($id, $user['id']);

        $this->logger->info('Profile deleted', ['user_id' => $user['id'], 'profile_id' => $id]);

        return ['success' => true];
    }

    /**
     * POST /profiles/{id}/connect
     * Привязать профиль к домену Bitrix24
     * Body: { domain, openline_id? }
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

        // Проверяем что домен установил приложение и принадлежит этому пользователю
        $bitrixData = $this->tokenRepository->findByDomain($domain);
        if (!$bitrixData) {
            return ['success' => false, 'errors' => ['domain' => 'Домен не найден. Установите приложение в Bitrix24.']];
        }

        if (!empty($bitrixData['user_id']) && (int)$bitrixData['user_id'] !== $user['id']) {
            return ['success' => false, 'errors' => ['domain' => 'Этот домен привязан к другому аккаунту']];
        }

        // Получаем или создаём connector_id
        $connectorId = $this->tokenRepository->getConnectorId($domain, $profile['messenger_type']);

        $this->profileRepository->saveConnection(
            $user['id'],
            $profileId,
            $domain,
            $connectorId,
            $openlineId
        );

        // Привязываем домен к пользователю
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
     * Отвязать профиль от домена
     * Body: { domain }
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
     * Установить ID открытой линии
     * Body: { domain, openline_id }
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

        // Обновляем также в bitrix_integration_tokens
        $connectorId = $this->tokenRepository->getConnectorId($domain, $profile['messenger_type']);
        if ($connectorId) {
            $this->tokenRepository->updateLine($connectorId, $openlineId);
        }

        return ['success' => true, 'openline_id' => $openlineId];
    }

    /**
     * GET /domains
     * Список доменов Bitrix24 текущего пользователя
     */
    public function getDomains(): array
    {
        $user    = $this->authMiddleware->require();
        $domains = $this->tokenRepository->getDomainsByUser($user['id']);

        return ['success' => true, 'domains' => $domains];
    }
}