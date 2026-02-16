# Руководство по установке

## Содержание

1. [Требования](#требования)
2. [Быстрый старт](#быстрый-старт)
3. [Детальная установка](#детальная-установка)
4. [Настройка мессенджеров](#настройка-мессенджеров)
5. [Первый запуск](#первый-запуск)
6. [Проверка работоспособности](#проверка-работоспособности)

---

## Требования

### Системные требования

- **PHP**: 7.4 или выше
- **MySQL**: 5.7+ или MariaDB 10.2+
- **Composer**: 2.0+
- **Веб-сервер**: Apache 2.4+ или Nginx 1.18+
- **SSL сертификат**: Обязателен (Let's Encrypt или коммерческий)

### Расширения PHP

```bash
# Обязательные
php-curl
php-json
php-pdo
php-pdo-mysql
php-mbstring

# Рекомендуемые
php-xml
php-zip
```

Проверка:
```bash
php -m | grep -E 'curl|json|pdo|mbstring'
```

### Bitrix24

- Активный портал Bitrix24
- Права администратора
- План с поддержкой открытых линий

---

## Быстрый старт

Для тех, кто хочет быстро развернуть приложение:

```bash
# 1. Клонирование
git clone https://github.com/your-repo/bitrix-telegram-integration.git
cd bitrix-telegram-integration

# 2. Установка зависимостей
composer install

# 3. Настройка
cp .env.example .env
nano .env  # Отредактируйте конфигурацию

# 4. Создание БД
mysql -u root -p < database/schema.sql

# 5. Установка прав
chmod -R 775 logs/
chown -R www-data:www-data .

# 6. Готово!
```

Перейдите к [настройке мессенджеров](#настройка-мессенджеров).

---

## Детальная установка

### Шаг 1: Загрузка проекта

#### Вариант A: Через Git

```bash
cd /var/www/
git clone https://github.com/Lead-Space/connector_with_max_marketplace.git
cd bitrix-telegram-integration
```

### Шаг 2: Установка зависимостей

```bash
# Если Composer не установлен
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Установка зависимостей проекта
composer install --no-dev --optimize-autoloader
```

**Ожидаемый вывод:**
```
Loading composer repositories with package information
Installing dependencies from lock file
...
Generating optimized autoload files
```

### Шаг 3: Настройка конфигурации

```bash
# Копирование примера конфигурации
cp .env.example .env

# Редактирование конфигурации
nano .env
```

**Минимальная конфигурация:**

```env
# База данных
DB_HOST=localhost
DB_NAME=bitrix_integration
DB_USER=bitrix_user
DB_PASS=secure_password_here

# Bitrix24 (получите после регистрации приложения)
BITRIX_CLIENT_ID=local.xxxxxxxxx.xxxxxxxx
BITRIX_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# URL вашего приложения
APP_URL=https://your-domain.com
APP_DEBUG=false

# Логирование
LOGGING_ENABLED=true
LOG_LEVEL=info
```

### Шаг 4: Создание базы данных

#### Автоматически (рекомендуется):

```bash
mysql -u root -p
```

```sql
-- Создание базы данных
CREATE DATABASE bitrix_integration 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

-- Создание пользователя
CREATE USER 'bitrix_user'@'localhost' 
  IDENTIFIED BY 'secure_password_here';

-- Выдача прав
GRANT ALL PRIVILEGES ON bitrix_integration.* 
  TO 'bitrix_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

#### Импорт схемы:

```bash
mysql -u bitrix_user -p bitrix_integration < database/schema.sql
```

Или создайте таблицы вручную:

```sql
USE bitrix_integration;

-- Таблица токенов
CREATE TABLE IF NOT EXISTS bitrix_integration_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    member_id VARCHAR(100),
    refresh_token TEXT,
    access_token TEXT,
    client_id VARCHAR(100),
    client_secret VARCHAR(100),
    hook_token TEXT,
    token_expires INT,
    connector_id VARCHAR(100),
    id_openline INT,
    telegram_bot_token VARCHAR(255),
    api_token_max TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_domain (domain),
    KEY idx_connector (connector_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица связей мессенджеров
CREATE TABLE IF NOT EXISTS messenger_chat_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    messenger_type VARCHAR(20) NOT NULL,
    messenger_chat_id VARCHAR(100) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    connector_id VARCHAR(100) NOT NULL,
    user_name VARCHAR(255),
    user_id VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_messenger_chat (messenger_type, messenger_chat_id),
    KEY idx_domain (domain),
    KEY idx_connector (connector_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Шаг 5: Настройка прав доступа

```bash
# Права на файлы
chmod -R 755 .

# Права на логи (запись)
chmod -R 775 logs/

# Владелец (для веб-сервера)
chown -R www-data:www-data .

# Защита .env
chmod 600 .env
```

Проверка:
```bash
ls -la
# Должно быть: -rw------- .env
# Должно быть: drwxrwxr-x logs/
```

### Шаг 6: Настройка веб-сервера

#### Apache

```bash
# Создание конфигурации
sudo nano /etc/apache2/sites-available/bitrix-integration.conf
```

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/bitrix-telegram-integration/public

    <Directory /var/www/bitrix-telegram-integration/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/bitrix-integration-error.log
    CustomLog ${APACHE_LOG_DIR}/bitrix-integration-access.log combined
</VirtualHost>
```

```bash
# Включение сайта
sudo a2ensite bitrix-integration.conf
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Nginx

```bash
sudo nano /etc/nginx/sites-available/bitrix-integration
```

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/bitrix-telegram-integration/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }

    access_log /var/log/nginx/bitrix-integration-access.log;
    error_log /var/log/nginx/bitrix-integration-error.log;
}
```

```bash
# Включение конфигурации
sudo ln -s /etc/nginx/sites-available/bitrix-integration /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

### Шаг 7: Установка SSL сертификата

```bash
# Установка certbot
sudo apt-get update
sudo apt-get install certbot python3-certbot-apache  # для Apache
# или
sudo apt-get install certbot python3-certbot-nginx   # для Nginx

# Получение сертификата
sudo certbot --apache -d your-domain.com  # для Apache
# или
sudo certbot --nginx -d your-domain.com   # для Nginx
```

Следуйте инструкциям certbot. После успешной установки:
- Сертификат будет автоматически обновляться
- HTTP будет перенаправлен на HTTPS

---

## Настройка Bitrix24

### Шаг 1: Регистрация приложения

1. **Войдите в Bitrix24**
   - Откройте ваш портал (например, `mycompany.bitrix24.ru`)
   - Войдите как администратор

2. **Создайте локальное приложение**
   ```
   Приложения → Разработчикам → Другое → Локальное приложение
   ```

3. **Заполните данные**:
   - **Название**: `Bitrix Multi-Messenger Integration`
   - **Код**: `bitrix_messenger_integration`
   - **Путь установки**: `https://your-domain.com/install_bitrix.php`
   - **Путь главного приложения**: `https://your-domain.com/app-vue/dist/index.html`

4. **Настройте права**:
   Отметьте следующие разделы:
   - ✅ `imconnector` - Коннекторы открытых линий
   - ✅ `im` - Чат и уведомления
   - ✅ `crm` - CRM (опционально)

5. **Сохраните приложение**

### Шаг 2: Получение Client ID и Secret

После создания приложения:

1. Откройте созданное приложение
2. Скопируйте **Client ID** и **Client Secret**
3. Добавьте их в `.env`:

```bash
nano .env
```

```env
BITRIX_CLIENT_ID=local.xxxxxxxxx.xxxxxxxx
BITRIX_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### Шаг 3: Установка приложения

1. В Bitrix24 откройте ваше приложение
2. Нажмите **"Установить"**
3. Подтвердите требуемые права
4. Дождитесь завершения установки

**Проверка установки:**
```bash
# Проверьте базу данных
mysql -u bitrix_user -p bitrix_integration -e "SELECT domain, connector_id FROM bitrix_integration_tokens;"
```

Должна появиться запись с вашим доменом.

---

## Настройка мессенджеров

### Telegram

#### 1. Создание бота

1. Откройте Telegram и найдите [@BotFather](https://t.me/BotFather)
2. Отправьте команду `/newbot`
3. Следуйте инструкциям:
   ```
   BotFather: Alright, a new bot. How are we going to call it?
   Вы: My Company Support Bot
   
   BotFather: Good. Now let's choose a username for your bot.
   Вы: mycompany_support_bot
   ```

4. Сохраните полученный токен:
   ```
   Use this token to access the HTTP API:
   1234567890:ABCdefGHIjklMNOpqrsTUVwxyz
   ```

#### 2. Добавление токена в систему

**Способ A: Через API**
```bash
curl -X POST https://your-domain.com/add_token_telegram.php \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "mycompany.bitrix24.ru",
    "telegram_bot_token": "1234567890:ABCdefGHIjklMNOpqrsTUVwxyz"
  }'
```

**Способ B: Прямо в БД**
```sql
UPDATE bitrix_integration_tokens 
SET telegram_bot_token = '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz' 
WHERE domain = 'mycompany.bitrix24.ru';
```

**Способ C: Через .env (глобально)**
```env
TELEGRAM_BOT_TOKEN=1234567890:ABCdefGHIjklMNOpqrsTUVwxyz
```

#### 3. Установка вебхука

```bash
curl "https://api.telegram.org/bot1234567890:ABCdefGHIjklMNOpqrsTUVwxyz/setWebhook?url=https://your-domain.com/webhook.php"
```

**Ожидаемый ответ:**
```json
{
  "ok": true,
  "result": true,
  "description": "Webhook was set"
}
```

#### 4. Проверка вебхука

```bash
curl "https://api.telegram.org/bot1234567890:ABCdefGHIjklMNOpqrsTUVwxyz/getWebhookInfo"
```

**Ожидаемый ответ:**
```json
{
  "ok": true,
  "result": {
    "url": "https://your-domain.com/webhook.php",
    "has_custom_certificate": false,
    "pending_update_count": 0
  }
}
```

#### 5. Привязка бота к домену

Отправьте боту в Telegram:
```
/start
mycompany.bitrix24.ru
```

Бот должен ответить:
```
✅ Домен успешно привязан!
🌐 Домен: mycompany.bitrix24.ru
```

---

### Max

#### 1. Получение API токена

1. Войдите в панель Max: https://platform.max.ru
2. Перейдите в раздел **API**
3. Создайте новый токен или используйте существующий
4. Скопируйте токен

#### 2. Добавление токена в систему

```bash
curl -X POST https://your-domain.com/add_token_max.php \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "mycompany.bitrix24.ru",
    "api_token_max": "your_max_api_token_here"
  }'
```

**Ожидаемый ответ:**
```json
{
  "success": true,
  "action": "created",
  "domain": "mycompany.bitrix24.ru"
}
```

#### 3. Настройка вебхука

```bash
curl -X POST https://your-domain.com/subscribe.php \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "mycompany.bitrix24.ru"
  }'
```

#### 4. Проверка подключения

Отправьте тестовое сообщение через Max мессенджер.
Проверьте логи:

```bash
tail -f /var/www/bitrix-telegram-integration/logs/$(date +%Y-%m-%d).txt
```

---

## Настройка открытых линий в Bitrix24

### 1. Создание открытой линии

1. В Bitrix24 перейдите: **CRM → Открытые линии**
2. Нажмите **"Создать открытую линию"**
3. Выберите **"Max Integration"** или **"Telegram Integration"**
4. Следуйте мастеру настройки

### 2. Привязка коннектора

1. Выберите созданную открытую линию
2. Нажмите **"Подключить мессенджер"**
3. Выберите нужный коннектор
4. Подтвердите настройки

### 3. Настройка правил обработки

1. **Ответственные**: Назначьте сотрудников
2. **Время работы**: Настройте график
3. **Приветствие**: Настройте автоматические сообщения
4. **CRM**: Настройте создание лидов/сделок

---

## Первый запуск

### Тестовое сообщение из Telegram

1. Найдите вашего бота в Telegram
2. Отправьте `/start`
3. Отправьте ваш домен: `mycompany.bitrix24.ru`
4. Отправьте тестовое сообщение: `Привет!`

**Ожидаемое поведение:**
- Сообщение появится в открытой линии Bitrix24
- Создастся диалог с клиентом

### Тестовое сообщение из Bitrix24

1. Откройте диалог в открытой линии
2. Отправьте ответ клиенту
3. Проверьте Telegram - должно прийти сообщение

### Проверка логов

```bash
# Логи приложения
tail -f /var/www/bitrix-telegram-integration/logs/$(date +%Y-%m-%d).txt

# Логи веб-сервера
tail -f /var/log/apache2/bitrix-integration-error.log  # Apache
tail -f /var/log/nginx/bitrix-integration-error.log    # Nginx
```

---

## Проверка работоспособности

### Автоматическая проверка

Откройте в браузере:
```
https://your-domain.com/setup_webhook.php
```

Эта страница покажет:
- ✅ Статус подключения к Telegram
- ✅ Статус подключения к Max
- ✅ Настройки вебхуков
- ✅ Общую конфигурацию системы

### Ручная проверка

#### 1. Проверка БД

```bash
mysql -u bitrix_user -p bitrix_integration -e "
  SELECT 
    domain, 
    connector_id, 
    CASE WHEN telegram_bot_token IS NOT NULL THEN 'YES' ELSE 'NO' END as has_telegram,
    CASE WHEN api_token_max IS NOT NULL THEN 'YES' ELSE 'NO' END as has_max
  FROM bitrix_integration_tokens;
"
```

#### 2. Проверка Telegram webhook

```bash
curl "https://api.telegram.org/botYOUR_BOT_TOKEN/getWebhookInfo" | jq .
```

#### 3. Проверка доступности endpoint

```bash
curl -I https://your-domain.com/webhook.php
# Должно вернуть: HTTP/2 200
```

#### 4. Тест отправки сообщения

```bash
curl -X POST https://your-domain.com/webhook.php \
  -H "Content-Type: application/json" \
  -d '{
    "update_id": 1,
    "message": {
      "message_id": 1,
      "from": {"id": 123456, "first_name": "Test"},
      "chat": {"id": 123456, "type": "private"},
      "text": "Test message"
    }
  }'
```

---

## Часто задаваемые вопросы (FAQ)

### Сообщения не доходят из Telegram в Bitrix24

**Проверьте:**
1. Webhook установлен: `curl "https://api.telegram.org/botTOKEN/getWebhookInfo"`
2. Домен привязан: отправьте `/status` боту
3. Логи: `tail -f logs/$(date +%Y-%m-%d).txt`

### Сообщения не доходят из Bitrix24 в мессенджер

**Проверьте:**
1. Connector ID привязан к открытой линии
2. Права доступа приложения в Bitrix24
3. Токены мессенджеров в БД

### Ошибка "Domain not set for MaxMessenger"

Max требует установки домена перед каждой операцией.
Проверьте, что в `messenger_chat_connections` есть запись для этого чата.

### SSL ошибки

Убедитесь что:
1. Сертификат валиден: `curl https://your-domain.com`
2. Нет смешанного контента (HTTP/HTTPS)
3. Certbot обновляет сертификат: `certbot renew --dry-run`

---

## Следующие шаги

После успешной установки:

1. **Настройте автоответы** в Bitrix24
2. **Добавьте сотрудников** для обработки диалогов
3. **Настройте правила CRM** для автосоздания лидов
4. **Настройте уведомления** для сотрудников
5. **Добавьте дополнительные мессенджеры** при необходимости

---

## Получение помощи

- 📖 Документация: `/docs`
- 🐛 Отладка: `/docs/TROUBLESHOOTING.md`
- 🚀 Развертывание: `/docs/DEPLOYMENT.md`
- 📡 API: `/docs/API.md`

При возникновении проблем проверьте:
1. Логи приложения
2. Логи веб-сервера
3. Системные логи
4. Раздел Troubleshooting