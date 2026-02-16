# API Документация

## Обзор

Bitrix Multi-Messenger Integration предоставляет RESTful API для интеграции с различными мессенджерами через Bitrix24.

## Базовый URL

```
https://your-domain.com/
```

## Аутентификация

Приложение использует OAuth 2.0 для аутентификации с Bitrix24. Токены управляются автоматически.

---

## Endpoints

### 1. Установка приложения

#### `GET /install.php`

Отображает страницу установки приложения в Bitrix24.

**Параметры запроса:**
- `PLACEMENT` (string, optional) - Тип размещения приложения
- `DOMAIN` (string, optional) - Домен Bitrix24
- `auth` (object, optional) - Данные авторизации

**Ответ:**
```html
HTML страница установки
```

---

#### `POST /install.php`

Обрабатывает установку приложения.

**Параметры:**
```json
{
  "event": "ONAPPINSTALL",
  "auth": {
    "domain": "example.bitrix24.ru",
    "access_token": "...",
    "refresh_token": "...",
    "expires_in": 3600,
    "member_id": "..."
  }
}
```

**Ответ:**
```json
{
  "status": "success",
  "domain": "example.bitrix24.ru"
}
```

---

### 2. Активация коннектора

#### `POST /activate.php`

Активирует коннектор для открытой линии.

**Параметры:**
```json
{
  "PLACEMENT": "SETTING_CONNECTOR",
  "PLACEMENT_OPTIONS": "{\"LINE\":123,\"ACTIVE_STATUS\":true}",
  "DOMAIN": "example.bitrix24.ru"
}
```

**Ответ:**
```html
HTML страница с результатом активации
```

---

### 3. Webhook обработчик

#### `POST /webhook.php`

Основной endpoint для обработки вебхуков от мессенджеров и Bitrix24.

**Поддерживаемые источники:**
- Telegram
- Max
- Bitrix24

**Пример запроса от Telegram:**
```json
{
  "update_id": 123456789,
  "message": {
    "message_id": 1,
    "from": {
      "id": 123456,
      "first_name": "John"
    },
    "chat": {
      "id": 123456,
      "type": "private"
    },
    "text": "Hello"
  }
}
```

**Пример запроса от Max:**
```json
{
  "message": {
    "recipient": {
      "chat_id": "user123"
    },
    "sender": {
      "user_id": "user123",
      "first_name": "John"
    },
    "body": {
      "text": "Hello",
      "mid": "msg_123"
    }
  }
}
```

**Пример запроса от Bitrix24:**
```json
{
  "event": "ONIMCONNECTORMESSAGEADD",
  "data": {
    "CONNECTOR": "max_abc123",
    "MESSAGES": [
      {
        "im": 0,
        "chat": {
          "id": "max_user123"
        },
        "message": {
          "id": ["123"],
          "text": "Hello from Bitrix"
        }
      }
    ]
  },
  "auth": {
    "domain": "example.bitrix24.ru",
    "access_token": "..."
  }
}
```

**Ответ:**
```json
{
  "status": "ok",
  "action": "message_sent",
  "source": "telegram"
}
```

---

### 4. Добавление токена Max

#### `POST /add_token_max.php`

Добавляет или обновляет API токен для Max.

**Параметры:**
```json
{
  "domain": "example.bitrix24.ru",
  "api_token_max": "your_max_api_token"
}
```

**Ответ:**
```json
{
  "success": true,
  "action": "created",
  "domain": "example.bitrix24.ru"
}
```

**Коды ошибок:**
- `400` - Неверные параметры
- `500` - Ошибка сервера

---

### 5. Добавление токена Telegram

#### `POST /add_token_telegram.php`

Добавляет или обновляет токен бота Telegram.

**Параметры:**
```json
{
  "domain": "example.bitrix24.ru",
  "telegram_bot_token": "1234567890:ABCdefGHIjklMNOpqrsTUVwxyz"
}
```

**Ответ:**
```json
{
  "success": true,
  "action": "updated",
  "domain": "example.bitrix24.ru"
}
```

---

### 6. Подписка на вебхуки Max

#### `POST /subscribe.php`

Настраивает вебхук для Max мессенджера.

**Параметры:**
```json
{
  "domain": "example.bitrix24.ru"
}
```

**Ответ:**
```json
{
  "status": "success",
  "domain": "example.bitrix24.ru"
}
```

---

### 7. Деинсталляция

#### `POST /uninstall_handler.php`

Обрабатывает деинсталляцию приложения.

**Параметры:**
```json
{
  "auth": {
    "domain": "example.bitrix24.ru"
  }
}
```

**Ответ:**
```json
{
  "status": "success",
  "message": "Application uninstalled successfully",
  "domain": "example.bitrix24.ru"
}
```

---

## Форматы сообщений

### Универсальный формат (внутренний)

```json
{
  "chat_id": "123456",
  "user_id": "123456",
  "user_name": "John Doe",
  "text": "Hello",
  "message_id": "msg_123",
  "timestamp": 1640000000,
  "type": "text",
  "files": [
    {
      "type": "photo",
      "url": "https://example.com/photo.jpg",
      "name": "photo.jpg"
    }
  ],
  "reply_to": null
}
```

### Типы сообщений

- `text` - Текстовое сообщение
- `photo` - Фотография
- `document` - Документ
- `voice` - Голосовое сообщение
- `video` - Видео
- `audio` - Аудио файл
- `sticker` - Стикер
- `location` - Геолокация
- `contact` - Контакт

---

## Коды ошибок

| Код | Описание |
|-----|----------|
| 200 | Успешный запрос |
| 400 | Неверные параметры |
| 401 | Не авторизован |
| 404 | Ресурс не найден |
| 500 | Внутренняя ошибка сервера |

---

## Примеры использования

### Отправка сообщения через API

```php
<?php
// Инициализация
$domain = 'example.bitrix24.ru';
$chatId = '123456';
$text = 'Hello from API';

// Формирование данных
$data = [
    'event' => 'ONIMCONNECTORMESSAGEADD',
    'data' => [
        'CONNECTOR' => 'telegram_abc123',
        'MESSAGES' => [
            [
                'chat' => ['id' => $chatId],
                'message' => ['text' => $text]
            ]
        ]
    ],
    'auth' => [
        'domain' => $domain
    ]
];

// Отправка запроса
$ch = curl_init('https://your-domain.com/webhook.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
curl_close($ch);

echo $response;
```

### Получение вебхука от мессенджера

```javascript
// Пример обработки вебхука на сервере Node.js
app.post('/webhook', (req, res) => {
  const data = req.body;
  
  // Определяем источник
  if (data.update_id) {
    // Telegram
    console.log('Telegram message:', data.message);
  } else if (data.message && data.message.recipient) {
    // Max
    console.log('Max message:', data.message);
  }
  
  res.json({ status: 'ok' });
});
```

---

## Rate Limits

- Telegram: 30 сообщений в секунду на бота
- Max: Зависит от вашего плана
- Bitrix24: 2 запроса в секунду по умолчанию

---

## Webhook Security

Все вебхуки должны использовать HTTPS. Рекомендуется:

1. Проверять подпись запросов (для Telegram)
2. Использовать секретный ключ (для Max)
3. Валидировать источник запроса
4. Логировать все входящие запросы

---
