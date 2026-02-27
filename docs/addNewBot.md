# Как добавить нового бота в систему

Архитектура построена так, что добавление нового мессенджера (например, VK Bot, WhatsApp, Viber) требует изменений в **4 файлах** и следует одному и тому же паттерну. Telegram Bot и Max уже реализованы — ориентируйся на них.

---

## Обзор архитектуры

```
Пользователь создаёт профиль (токен) в UI
    ↓
ProfileController валидирует токен → сохраняет профиль → регистрирует webhook
    ↓
Мессенджер шлёт POST на /webhook.php?{type}_token=TOKEN
    ↓
MessageDetector определяет источник по параметру в URL
    ↓
WebhookController.handleXxxBotIncoming() → находит профиль → домен → шлёт в Bitrix24
    ↓
Bitrix24 отвечает → handleBitrixToMessenger() → по профилю находит токен → шлёт обратно
```

Webhook URL всегда имеет вид: `/public/webhook.php?{messenger}_token=TOKEN`

Это позволяет идентифицировать профиль без дополнительных таблиц.

---

## Шаг 1 — ProfileController.php

Добавить обработку нового типа в три метода: `create()`, `update()`, `delete()`.

### 1.1 В методе `create()` — после блока `max`:

```php
// Валидируем токен нового бота
if ($type === 'vk_bot') {
    $botInfo = $this->callVkApi($token, 'groups.getById');
    if (empty($botInfo['success'])) {
        return ['success' => false, 'errors' => [
            'token' => 'Ошибка VK API: ' . ($botInfo['error'] ?? 'Неверный токен'),
        ]];
    }
    $extra = [
        'bot_id'   => $botInfo['data']['response'][0]['id']   ?? null,
        'bot_name' => $botInfo['data']['response'][0]['name'] ?? null,
    ];
}
```

После создания профиля — регистрируем webhook:

```php
if ($type === 'vk_bot') {
    $webhookResult = $this->registerVkWebhook($token);
    if (!($webhookResult['success'] ?? false)) {
        $profile['webhook_warning'] = 'Профиль создан, но webhook не установлен: '
            . ($webhookResult['error'] ?? 'неизвестная ошибка');
    } else {
        $profile['webhook_set'] = true;
    }
}
```

### 1.2 В методе `update()` — при смене токена:

```php
if ($profile['messenger_type'] === 'vk_bot' && !empty($fields['token'])) {
    // 1. Валидируем новый токен
    // 2. Удаляем старый webhook
    if (!empty($profile['token'])) {
        $this->deleteVkWebhook($profile['token']);
    }
    // 3. Регистрируем новый
    $this->registerVkWebhook($newToken);
    // 4. Обновляем extra
}
```

### 1.3 В методе `delete()`:

```php
if ($profile['messenger_type'] === 'vk_bot' && !empty($profile['token'])) {
    $this->deleteVkWebhook($profile['token']);
}
```

### 1.4 Приватные методы:

```php
private function registerVkWebhook(string $token): array
{
    $appUrl     = rtrim($this->config['app']['url'] ?? '', '/');
    $webhookUrl = $appUrl . '/public/webhook.php?vk_token=' . urlencode($token);

    return $this->callVkApi($token, 'groups.setLongPollSettings', [
        'api_version' => '5.131',
        'enabled'     => 1,
        'url'         => $webhookUrl,
        // ...нужные event-типы
    ]);
}

private function deleteVkWebhook(string $token): array
{
    return $this->callVkApi($token, 'groups.setLongPollSettings', ['enabled' => 0]);
}

private function callVkApi(string $token, string $method, array $params = []): array
{
    // POST https://api.vk.com/method/{method}
    // Authorization через access_token в параметрах
    // Возвращай ['success' => bool, 'data' => array, 'error' => string]
}
```

> **Важно:** формат возврата `callXxxApi()` должен быть единообразным:
>
> `['success' => bool, 'data' => array]` при успехе
>
> `['success' => false, 'error' => string]` при ошибке

---

## Шаг 2 — MessageDetector.php

Добавить константу и метод детектирования.

### 2.1 Константа:

```php
public const SOURCE_VK_BOT = 'vk_bot';
```

### 2.2 Метод детектирования — в `detectSource()`, перед `isMaxMessage()`:

```php
if ($this->isVkBotMessage($data)) {
    return self::SOURCE_VK_BOT;
}
```

### 2.3 Сам метод — главный признак это `?vk_token=` в URL:

```php
private function isVkBotMessage(array $data): bool
{
    if (!empty($_GET['vk_token'])) {
        return true;
    }
    // Опционально: детект по структуре тела если токен не передан
    // if (isset($data['type']) && isset($data['group_id'])) return true;
    return false;
}
```

> **Порядок проверок в `detectSource()` важен.** Всегда проверяй Bitrix первым, потом специфичные боты (по URL-параметру), потом общие детекторы по структуре тела.

---

## Шаг 3 — WebhookController.php

Три места: `switch`, новый `handleXxxBotIncoming()`, ответ из Bitrix.

### 3.1 В `handleWebhook()` — добавить case:

```php
case MessageDetector::SOURCE_VK_BOT:
    return $this->handleVkBotIncoming($data);
```

### 3.2 Новый метод `handleVkBotIncoming()`:

Копируй структуру `handleTelegramBotIncoming()` или `handleMaxBotIncoming()` и адаптируй под формат входящего payload мессенджера:

```php
private function handleVkBotIncoming(array $data): array
{
    $token = $_GET['vk_token'] ?? null;
    if (empty($token)) {
        return ['status' => 'ok', 'message' => 'vk_token missing'];
    }

    // 1. Находим профиль по токену
    $profile = $this->findProfileByToken($token, 'vk_bot');
    if (!$profile) {
        return ['status' => 'ok', 'message' => 'profile not found'];
    }

    $profileId = (int)$profile['id'];

    // 2. Извлекаем нужные поля из payload (зависит от API мессенджера)
    $message  = $data['object']['message'] ?? null;
    if (!$message) {
        return ['status' => 'ok', 'action' => 'non_message_update'];
    }

    $chatId   = (string)($message['peer_id'] ?? '');
    $userId   = (string)($message['from_id'] ?? '');
    $userName = 'VK User ' . $userId;
    $text     = $message['text'] ?? '';

    // 3. Находим домен по profile_id
    $domain = $this->getDomainByProfileId($profileId);
    if (!$domain) {
        return ['status' => 'error', 'message' => 'Domain not configured'];
    }

    // 4. Получаем connector_id
    $connectorId = $this->tokenRepository->getConnectorId($domain, 'vk_bot');
    if (!$connectorId) {
        return ['status' => 'error', 'message' => 'Connector not found'];
    }

    // 5. Сохраняем связку чат ↔ домен ↔ профиль
    $this->chatRepository->saveConnection('vk_bot', $chatId, $domain, $connectorId, $userName, $userId);
    $this->saveProfileIdForChat('vk_bot', $chatId, $profileId);

    // 6. Проверяем линию
    $lineId = $this->tokenRepository->getLineByConnectorId($connectorId);
    if (!$lineId) {
        return ['status' => 'error', 'message' => 'Line not configured'];
    }

    // 7. Формируем сообщение для Bitrix24
    // ВАЖНО: префикс в chat.id должен быть уникальным (см. таблицу префиксов ниже)
    $bitrixMsg = [
        'user'    => ['id' => $chatId, 'name' => $userName],
        'message' => ['date' => time(), 'text' => $text],
        'chat'    => ['id' => 'vkbot_' . $chatId],
    ];

    $this->bitrixService->sendMessages($connectorId, $lineId, [$bitrixMsg], $domain);

    return ['status' => 'ok', 'action' => 'vk_bot_message_sent'];
}
```

### 3.3 Ответ из Bitrix — в `handleBitrixToMessenger()`:

Добавь блок перед `// ── Прочие мессенджеры`:

```php
if ($messengerType === 'vk_bot') {
    $vkToken = $this->getVkTokenByChatId($chatId);
    if (!$vkToken) {
        $this->logger->error('vk_bot: token not found', ['chatId' => $chatId]);
        $this->sendDeliveryConfirmation($connectorId, $message, $bitrixChatId, $domain, false);
        continue;
    }
    $result = $this->sendVkBotMessage($vkToken, $chatId, $text);
    $this->sendDeliveryConfirmation($connectorId, $message, $bitrixChatId, $domain, !empty($result['success']));
    continue;
}
```

### 3.4 Вспомогательные методы:

```php
private function getVkTokenByChatId(string $chatId): ?string
{
    // Аналог getBotTokenByChatId() и getMaxTokenByChatId()
    $stmt = $this->profileRepository->getPdo()->prepare("
        SELECT ump.token
        FROM messenger_chat_connections mcc
        JOIN user_messenger_profiles ump ON ump.id = mcc.profile_id
        WHERE mcc.messenger_type    = 'vk_bot'
          AND mcc.messenger_chat_id = ?
          AND mcc.is_active         = 1
          AND ump.is_active         = 1
        LIMIT 1
    ");
    $stmt->execute([$chatId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row['token'] ?? null;
}

private function sendVkBotMessage(string $token, string $peerId, string $text): array
{
    // POST https://api.vk.com/method/messages.send
    // ...
    return ['success' => $httpCode === 200];
}
```

### 3.5 Зарегистрировать префикс в `detectMessengerTypeFromChatId()` и `cleanChatId()`:

```php
// detectMessengerTypeFromChatId():
if (str_starts_with($chatId, 'vkbot_')) return 'vk_bot';

// cleanChatId() — массив $prefixes:
'vk_bot' => ['vkbot_'],
```

---

## Шаг 4 — ALLOWED_TYPES

В `ProfileController.php` добавить тип в константу:

```php
private const ALLOWED_TYPES = ['max', 'telegram_bot', 'telegram_user', 'vk_bot'];
```

---

## Таблица существующих префиксов

Префикс используется как `chat.id` при отправке в Bitrix24. Должен быть уникальным.

| Мессенджер        | Префикс | URL-параметр |
| --------------------------- | -------------- | -------------------- |
| Telegram (legacy)           | `tg_`        | —                   |
| Telegram User               | `tguser_`    | —                   |
| Telegram Bot                | `tgbot_`     | `?bot_token=`      |
| Max                         | `max_`       | `?max_token=`      |
| **Новый бот** | `vkbot_`     | `?vk_token=`       |

---

## Чеклист перед деплоем

* [ ] `ALLOWED_TYPES` обновлён
* [ ] `ProfileController`: блоки в `create` / `update` / `delete`
* [ ] `ProfileController`: приватные методы `registerXxxWebhook`, `deleteXxxWebhook`, `callXxxApi`
* [ ] `MessageDetector`: константа `SOURCE_XXX` + метод `isXxxBotMessage()`
* [ ] `MessageDetector`: вызов в `detectSource()` в правильном порядке
* [ ] `WebhookController`: `case SOURCE_XXX` в switch
* [ ] `WebhookController`: метод `handleXxxBotIncoming()`
* [ ] `WebhookController`: блок ответа в `handleBitrixToMessenger()`
* [ ] `WebhookController`: методы `getXxxTokenByChatId()`, `sendXxxBotMessage()`
* [ ] `WebhookController`: префикс в `detectMessengerTypeFromChatId()` и `cleanChatId()`
* [ ] Убедиться что `config['app']['url']` заполнен (нужен для webhook URL)
* [ ] Проверить webhook через API мессенджера после создания первого профиля
