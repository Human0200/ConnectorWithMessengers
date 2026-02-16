# Руководство по устранению неполадок

## Содержание

1. [Диагностика проблем](#диагностика-проблем)
2. [Проблемы установки](#проблемы-установки)
3. [Проблемы с Telegram](#проблемы-с-telegram)
4. [Проблемы с Max](#проблемы-с-max)
5. [Проблемы с Bitrix24](#проблемы-с-bitrix24)
6. [Проблемы с доставкой сообщений](#проблемы-с-доставкой-сообщений)
7. [Проблемы с производительностью](#проблемы-с-производительностью)
8. [Ошибки базы данных](#ошибки-базы-данных)

---

## Диагностика проблем

### Проверка логов

#### Логи приложения
```bash
# Просмотр сегодняшних логов
tail -f /var/www/bitrix-telegram-integration/logs/$(date +%Y-%m-%d).txt

# Поиск ошибок
grep -i "error" /var/www/bitrix-telegram-integration/logs/*.txt

# Последние 100 записей
tail -n 100 /var/www/bitrix-telegram-integration/logs/$(date +%Y-%m-%d).txt
```

#### Логи веб-сервера
```bash
# Apache
tail -f /var/log/apache2/bitrix-integration-error.log
tail -f /var/log/apache2/bitrix-integration-access.log

# Nginx
tail -f /var/log/nginx/bitrix-integration-error.log
tail -f /var/log/nginx/bitrix-integration-access.log
```

#### Системные логи
```bash
# PHP ошибки
tail -f /var/log/php7.4-fpm.log

# Системные ошибки
tail -f /var/log/syslog | grep bitrix
```

### Включение режима отладки

Временно включите debug режим для подробных логов:

```bash
nano .env
```

```env
APP_DEBUG=true
LOG_LEVEL=debug
```

**⚠️ ВАЖНО:** Не забудьте отключить после отладки!

### Тестовые запросы

#### Проверка доступности endpoint
```bash
curl -I https://your-domain.com/webhook.php
# Ожидается: HTTP/2 200
```

#### Тест webhook Telegram
```bash
curl -X POST https://your-domain.com/webhook.php \
  -H "Content-Type: application/json" \
  -d '{
    "update_id": 999999,
    "message": {
      "message_id": 1,
      "from": {"id": 123, "first_name": "TestUser"},
      "chat": {"id": 123, "type": "private"},
      "text": "Test message",
      "date": 1640000000
    }
  }'
```

#### Проверка БД подключения
```bash
php -r "
require 'vendor/autoload.php';
\$config = require 'config/config.php';
try {
    \$pdo = new PDO(
        'mysql:host='.\$config['database']['host'].';dbname='.\$config['database']['name'],
        \$config['database']['user'],
        \$config['database']['password']
    );
    echo 'Database: OK';
} catch (PDOException \$e) {
    echo 'Database: ERROR - '.\$e->getMessage();
}
"
```

---

## Проблемы установки

### Ошибка: "Composer dependencies not found"

**Причина:** Зависимости не установлены

**Решение:**
```bash
cd /var/www/bitrix-telegram-integration
composer install --no-dev
```

---

### Ошибка: "Permission denied" при записи в logs/

**Причина:** Неправильные права доступа

**Решение:**
```bash
chmod -R 775 logs/
chown -R www-data:www-data logs/

# Для Nginx может быть другой пользователь:
chown -R nginx:nginx logs/
```

**Проверка:**
```bash
ls -la logs/
# Должно быть: drwxrwxr-x www-data www-data
```

---

### Ошибка: "Database connection failed"

**Причина:** Неверные данные подключения к БД

**Решение:**

1. Проверьте .env файл:
```bash
cat .env | grep DB_
```

2. Проверьте подключение вручную:
```bash
mysql -h localhost -u bitrix_user -p bitrix_integration
```

3. Проверьте права пользователя:
```sql
SHOW GRANTS FOR 'bitrix_user'@'localhost';
```

---

### Ошибка: "Table doesn't exist"

**Причина:** Таблицы БД не созданы

**Решение:**
```bash
mysql -u bitrix_user -p bitrix_integration < database/schema.sql
```

Или создайте вручную (см. INSTALLATION.md)

---

## Проблемы с Telegram

### Сообщения не приходят из Telegram в Bitrix24

#### Проверка 1: Webhook установлен?

```bash
curl "https://api.telegram.org/botYOUR_TOKEN/getWebhookInfo"
```

**Ожидаемый ответ:**
```json
{
  "ok": true,
  "result": {
    "url": "https://your-domain.com/webhook.php",
    "has_custom_certificate": false,
    "pending_update_count": 0,
    "last_error_date": 0
  }
}
```

**Если webhook не установлен:**
```bash
curl "https://api.telegram.org/botYOUR_TOKEN/setWebhook?url=https://your-domain.com/webhook.php"
```

**Если есть ошибки (last_error_date > 0):**
```json
{
  "url": "https://your-domain.com/webhook.php",
  "last_error_date": 1640000000,
  "last_error_message": "Wrong response from the webhook: 500 Internal Server Error"
}
```

Проверьте логи сервера на наличие ошибок 500.

#### Проверка 2: Домен привязан?

Отправьте боту:
```
/status
```

**Ожидаемый ответ:**
```
✅ Аккаунт привязан
🌐 Домен: mycompany.bitrix24.ru
```

**Если не привязан:**
```
❌ Аккаунт не привязан
Отправьте ваш домен Bitrix24 для привязки.
```

Отправьте домен:
```
mycompany.bitrix24.ru
```

#### Проверка 3: Токен бота в БД?

```sql
SELECT domain, 
  CASE WHEN telegram_bot_token IS NOT NULL 
    THEN 'EXISTS' 
    ELSE 'MISSING' 
  END as token_status
FROM bitrix_integration_tokens
WHERE domain = 'mycompany.bitrix24.ru';
```

**Если токена нет:**
```bash
curl -X POST https://your-domain.com/add_token_telegram.php \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "mycompany.bitrix24.ru",
    "telegram_bot_token": "YOUR_TOKEN"
  }'
```

#### Проверка 4: SSL сертификат валиден?

Telegram требует валидный SSL:
```bash
curl -I https://your-domain.com
```

Проверьте на ошибки SSL:
```bash
openssl s_client -connect your-domain.com:443 -servername your-domain.com
```

---

### Сообщения не доходят из Bitrix24 в Telegram

#### Проверка 1: Connector ID привязан?

```sql
SELECT domain, connector_id, id_openline 
FROM bitrix_integration_tokens 
WHERE domain = 'mycompany.bitrix24.ru';
```

**Должно быть:**
- `connector_id` не NULL
- `id_openline` не NULL (ID открытой линии)

#### Проверка 2: Чат привязан к connector?

```sql
SELECT * FROM messenger_chat_connections 
WHERE messenger_type = 'telegram' 
  AND domain = 'mycompany.bitrix24.ru';
```

**Если записи нет:**
Отправьте боту сообщение в Telegram - связь создастся автоматически.

#### Проверка 3: Токен Bitrix24 актуален?

```sql
SELECT domain, 
  FROM_UNIXTIME(token_expires) as expires_at,
  CASE WHEN token_expires > UNIX_TIMESTAMP() 
    THEN 'VALID' 
    ELSE 'EXPIRED' 
  END as status
FROM bitrix_integration_tokens
WHERE domain = 'mycompany.bitrix24.ru';
```

**Если токен истек:**
Он обновится автоматически при следующем запросе. Проверьте логи.

---

### Ошибка: "Bot was blocked by the user"

**Причина:** Пользователь заблокировал бота

**Решение:**
1. Попросите пользователя разблокировать бота
2. Пользователь должен отправить `/start`
3. Затем снова отправить свой домен

---

### Ошибка: "Chat not found"

**Причина:** Chat ID не найден в БД или неактивен

**Решение:**
```sql
-- Проверка статуса
SELECT * FROM messenger_chat_connections 
WHERE messenger_chat_id = '123456';

-- Активация если деактивирован
UPDATE messenger_chat_connections 
SET is_active = TRUE 
WHERE messenger_chat_id = '123456';
```

---

## Проблемы с Max

### Сообщения не приходят из Max в Bitrix24

#### Проверка 1: Токен Max в БД?

```sql
SELECT domain, 
  CASE WHEN api_token_max IS NOT NULL 
    THEN 'EXISTS' 
    ELSE 'MISSING' 
  END as token_status
FROM bitrix_integration_tokens
WHERE domain = 'mycompany.bitrix24.ru';
```

**Если токена нет:**
```bash
curl -X POST https://your-domain.com/add_token_max.php \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "mycompany.bitrix24.ru",
    "api_token_max": "YOUR_MAX_TOKEN"
  }'
```

#### Проверка 2: Webhook настроен?

```bash
curl -X POST https://your-domain.com/subscribe.php \
  -H "Content-Type: application/json" \
  -d '{"domain": "mycompany.bitrix24.ru"}'
```

#### Проверка 3: Связь с чатом создана?

Для Max связь создается автоматически при первом сообщении.
Проверьте:
```sql
SELECT * FROM messenger_chat_connections 
WHERE messenger_type = 'max' 
  AND domain = 'mycompany.bitrix24.ru';
```

---

### Ошибка: "Domain not set for MaxMessenger"

**Причина:** Домен не установлен перед операцией

**Это известная особенность Max мессенджера.**

**Решение автоматическое:**
- При первом сообщении от Max система определит домен
- Создаст связь в `messenger_chat_connections`
- Последующие сообщения будут работать

**Ручное исправление:**
```sql
INSERT INTO messenger_chat_connections 
(messenger_type, messenger_chat_id, domain, connector_id, is_active)
VALUES 
('max', 'user123', 'mycompany.bitrix24.ru', 'max_abc123', TRUE);
```

---

### Ошибка: "attachment.not.ready"

**Причина:** Файл еще загружается на сервера Max

**Решение:** Система автоматически повторяет попытку отправки с экспоненциальной задержкой (2, 4, 8 секунд).

**Если проблема сохраняется:**
```bash
# Проверьте логи
grep "attachment.not.ready" logs/*.txt

# Увеличьте количество попыток в MaxService.php
# или добавьте большую задержку
```

---

### Ошибка при загрузке файлов в Max

**Причина:** Проблема с загрузкой файла или его размером

**Проверка:**
```bash
# Проверьте размер файла
ls -lh /path/to/file

# Проверьте права на чтение
cat /path/to/file > /dev/null

# Проверьте MIME тип
file --mime-type /path/to/file
```

**Решение:**
1. Убедитесь что файл < 20MB
2. Проверьте что MIME тип поддерживается
3. Проверьте логи на детали ошибки

---

## Проблемы с Bitrix24

### Ошибка: "expired_token"

**Причина:** Access token истек

**Решение:** Система обновляет токен автоматически.

**Ручное обновление:**
```bash
# Проверьте refresh_token в БД
mysql -u bitrix_user -p bitrix_integration -e "
  SELECT domain, 
    FROM_UNIXTIME(token_expires) as expires,
    CASE WHEN refresh_token IS NOT NULL THEN 'YES' ELSE 'NO' END as has_refresh
  FROM bitrix_integration_tokens;
"

# Если refresh_token есть, он обновится при следующем запросе
# Если нет - переустановите приложение
```

---

### Ошибка: "invalid_token"

**Причина:** Токен невалидный (приложение удалено или переустановлено)

**Решение:**
1. Удалите запись из БД:
```sql
DELETE FROM bitrix_integration_tokens 
WHERE domain = 'mycompany.bitrix24.ru';
```

2. Переустановите приложение в Bitrix24

---

### Ошибка: "QUERY_LIMIT_EXCEEDED"

**Причина:** Превышен лимит запросов (2/сек по умолчанию)

**Решение:**
1. Добавьте задержки между запросами
2. Используйте batch запросы где возможно
3. Оптимизируйте код

**Временное решение:**
```php
// В BitrixService.php добавьте задержку
sleep(1); // 1 секунда между запросами
```

---

### Ошибка: "Connector not registered"

**Причина:** Коннектор не зарегистрирован в Bitrix24

**Решение:**
```bash
# Проверьте connector_id
mysql -u bitrix_user -p bitrix_integration -e "
  SELECT connector_id FROM bitrix_integration_tokens 
  WHERE domain = 'mycompany.bitrix24.ru';
"

# Если NULL - создайте:
# Откройте в Bitrix24: Приложения → Ваше приложение → Переустановить
```

---

## Проблемы с доставкой сообщений

### Сообщения дублируются

**Причина:** Множественные webhook или обработчики

**Решение:**

1. Проверьте webhook Telegram:
```bash
curl "https://api.telegram.org/botTOKEN/getWebhookInfo"
```

Должен быть только один URL.

2. Проверьте events в Bitrix24:
```bash
# В Bitrix24 REST API
https://your-domain.bitrix24.ru/rest/event.get.json?auth=YOUR_TOKEN
```

Должен быть один `OnImConnectorMessageAdd`.

3. Проверьте БД на дубли:
```sql
SELECT domain, COUNT(*) as count 
FROM bitrix_integration_tokens 
GROUP BY domain 
HAVING count > 1;
```

---

### Сообщения приходят с задержкой

**Проверка 1: Скорость сервера**
```bash
time curl https://your-domain.com/webhook.php
# Должно быть < 1 секунда
```

**Проверка 2: Очередь сообщений**
```bash
# Telegram pending updates
curl "https://api.telegram.org/botTOKEN/getWebhookInfo" | jq .result.pending_update_count
# Должно быть 0
```

**Проверка 3: Нагрузка сервера**
```bash
top
htop

# Проверка дисковой активности
iostat -x 1 10
```

**Решение:**
- Оптимизируйте код
- Увеличьте ресурсы сервера
- Настройте кеширование
- Используйте очереди (Redis/RabbitMQ)

---

### Файлы не передаются

#### Из мессенджера в Bitrix24

**Проверка:**
```bash
# Найдите в логах обработку файлов
grep -i "file" logs/$(date +%Y-%m-%d).txt
grep -i "photo" logs/$(date +%Y-%m-%d).txt
grep -i "document" logs/$(date +%Y-%m-%d).txt
```

**Типичные проблемы:**
1. Файл слишком большой (> 20MB для Telegram)
2. Неподдерживаемый формат
3. Ошибка при скачивании

**Решение:**
Добавьте детальное логирование в `WebhookController::prepareMessagesForBitrix()`

#### Из Bitrix24 в мессенджер

**Проверка:**
```bash
# Проверьте downloadLink в логах
grep "downloadLink" logs/*.txt
```

**Типичные проблемы:**
1. Ссылка недоступна (требуется авторизация)
2. SSL проблемы при скачивании
3. Timeout при скачивании больших файлов

**Решение:**
```php
// В MaxService.php увеличьте timeout
curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 минут
```

---

## Проблемы с производительностью

### Высокая нагрузка на CPU

**Диагностика:**
```bash
# Топ процессов
top -bn1 | head -20

# PHP процессы
ps aux | grep php
```

**Решения:**
1. Включите OpCache:
```bash
nano /etc/php/7.4/fpm/php.ini
```

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
```

2. Оптимизируйте autoloader:
```bash
composer dump-autoload --optimize --classmap-authoritative
```

3. Включите APCu для кеширования:
```bash
apt-get install php-apcu
```

---

### Медленные запросы к БД

**Диагностика:**
```sql
-- Включите slow query log
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1;

-- Проверьте медленные запросы
SELECT * FROM mysql.slow_log ORDER BY start_time DESC LIMIT 10;
```

**Решения:**

1. Добавьте индексы:
```sql
-- Проверьте существующие
SHOW INDEX FROM bitrix_integration_tokens;
SHOW INDEX FROM messenger_chat_connections;

-- Добавьте если нужно
CREATE INDEX idx_domain_active ON bitrix_integration_tokens(domain, is_active);
CREATE INDEX idx_messenger_chat ON messenger_chat_connections(messenger_type, messenger_chat_id, is_active);
```

2. Оптимизируйте таблицы:
```sql
OPTIMIZE TABLE bitrix_integration_tokens;
OPTIMIZE TABLE messenger_chat_connections;
```

---

### Ошибки Out of Memory

**Диагностика:**
```bash
# Проверка использования памяти
free -h
ps aux --sort=-%mem | head -10
```

**Решения:**

1. Увеличьте memory_limit PHP:
```bash
nano /etc/php/7.4/fpm/php.ini
```

```ini
memory_limit = 256M
```

2. Увеличьте RAM сервера

3. Оптимизируйте код:
```php
// Используйте generators вместо массивов
// Очищайте большие переменные
unset($largeArray);
```

---

## Ошибки базы данных

### Ошибка: "Too many connections"

**Диагностика:**
```sql
SHOW VARIABLES LIKE 'max_connections';
SHOW STATUS LIKE 'Threads_connected';
```

**Решение:**
```sql
SET GLOBAL max_connections = 200;
```

Постоянно:
```bash
nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

```ini
[mysqld]
max_connections = 200
```

---

### Ошибка: "Table is marked as crashed"

**Решение:**
```sql
REPAIR TABLE bitrix_integration_tokens;
REPAIR TABLE messenger_chat_connections;
```

---

### Потеря данных

**Восстановление из backup:**
```bash
# Файлы
tar -xzf /backups/bitrix-integration/files-20240120.tar.gz -C /var/www/bitrix-telegram-integration

# БД
gunzip /backups/bitrix-integration/db-20240120.sql.gz
mysql -u bitrix_user -p bitrix_integration < /backups/bitrix-integration/db-20240120.sql
```

---

## Получение помощи

Если проблема не решена:

1. **Соберите информацию:**
```bash
# Версия PHP
php -v

# Версия MySQL
mysql --version

# Логи последних ошибок
tail -n 50 logs/$(date +%Y-%m-%d).txt > error-report.txt

# Конфигурация (без паролей!)
cat .env | grep -v PASS | grep -v SECRET | grep -v TOKEN
```

2. **Проверьте документацию:**
- README.md
- INSTALLATION.md
- DEPLOYMENT.md
- API.md

3. **Создайте issue:**
- Опишите проблему
- Приложите логи
- Укажите версию PHP, MySQL, ОС
- Опишите шаги воспроизведения

---

## Полезные команды

```bash
# Полная очистка и перезапуск
systemctl restart php7.4-fpm
systemctl restart apache2  # или nginx
systemctl restart mysql

# Проверка статуса
systemctl status php7.4-fpm
systemctl status apache2  # или nginx
systemctl status mysql

# Мониторинг в реальном времени
watch -n 1 'tail -n 20 logs/$(date +%Y-%m-%d).txt'

# Быстрый тест всей системы
curl -I https://your-domain.com/webhook.php && \
curl "https://api.telegram.org/botTOKEN/getWebhookInfo" && \
mysql -u bitrix_user -p -e "USE bitrix_integration; SHOW TABLES;"
```