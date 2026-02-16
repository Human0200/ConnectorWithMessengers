# Руководство по развертыванию

## Требования к серверу

### Минимальные требования

- **PHP**: >= 7.4
- **MySQL**: >= 5.7 или MariaDB >= 10.2
- **Веб-сервер**: Apache 2.4+ или Nginx 1.18+
- **RAM**: минимум 512MB
- **Disk**: минимум 1GB свободного места
- **SSL**: Обязательно (Let's Encrypt или коммерческий сертификат)

### Расширения PHP

Обязательные:
```bash
php-curl
php-json
php-pdo
php-pdo-mysql
php-mbstring
```

Проверка установленных расширений:
```bash
php -m | grep -E 'curl|json|pdo|mbstring'
```

---

## Предварительная подготовка

### 1. Создание базы данных

```sql
CREATE DATABASE bitrix_telegram_integration CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bitrix_user'@'localhost' IDENTIFIED BY 'your_strong_password';
GRANT ALL PRIVILEGES ON bitrix_telegram_integration.* TO 'bitrix_user'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Создание таблиц

```sql
USE bitrix_telegram_integration;

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
    KEY idx_connector (connector_id),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица связей чатов
CREATE TABLE IF NOT EXISTS messenger_chat_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    messenger_type VARCHAR(20) NOT NULL COMMENT 'telegram, max, whatsapp, viber',
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
    KEY idx_connector (connector_id),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Установка на сервер

### Вариант 1: Установка через Git

```bash
# Клонирование репозитория
cd /var/www/
git clone https://github.com/your-repo/bitrix-telegram-integration.git
cd bitrix-telegram-integration

# Установка зависимостей
composer install --no-dev --optimize-autoloader

# Копирование конфигурации
cp .env.example .env

# Установка прав
chmod -R 755 .
chmod -R 775 logs/
chown -R www-data:www-data .
```

### Вариант 2: Загрузка архива

```bash
# Загрузка на сервер
cd /var/www/
wget https://your-domain.com/releases/bitrix-integration-v1.0.0.zip
unzip bitrix-integration-v1.0.0.zip
cd bitrix-telegram-integration

# Установка composer (если не установлен)
curl -sS https://getcomposer.org/installer | php
php composer.phar install --no-dev

# Настройка прав
chmod -R 755 .
chmod -R 775 logs/
chown -R www-data:www-data .
```

---

## Конфигурация

### 1. Настройка .env файла

```bash
nano .env
```

Заполните все необходимые параметры:

```env
# Database Configuration
DB_HOST=localhost
DB_NAME=bitrix_telegram_integration
DB_USER=bitrix_user
DB_PASS=your_strong_password

# Bitrix24 Configuration
BITRIX_CLIENT_ID=local.xxxxxxxxx.xxxxxxxx
BITRIX_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
BITRIX_WEBHOOK_URL=

# Telegram Configuration (опционально)
TELEGRAM_BOT_TOKEN=1234567890:ABCdefGHIjklMNOpqrsTUVwxyz

# Max Configuration (опционально)
MAX_API_URL=https://platform-api.max.ru
MAX_API_KEY=

# Application Configuration
APP_URL=https://your-domain.com
APP_DEBUG=false

# Logging Configuration
LOGGING_ENABLED=true
LOG_LEVEL=info
```

### 2. Проверка конфигурации

```bash
php -r "require 'vendor/autoload.php'; \$config = require 'config/config.php'; var_dump(\$config);"
```

---

## Настройка веб-сервера

### Apache

Создайте конфигурацию виртуального хоста:

```bash
nano /etc/apache2/sites-available/bitrix-integration.conf
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

Включите сайт:
```bash
a2ensite bitrix-integration.conf
a2enmod rewrite
systemctl restart apache2
```

### Nginx

Создайте конфигурацию:

```bash
nano /etc/nginx/sites-available/bitrix-integration
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

Включите конфигурацию:
```bash
ln -s /etc/nginx/sites-available/bitrix-integration /etc/nginx/sites-enabled/
nginx -t
systemctl restart nginx
```

---

## Настройка SSL (Let's Encrypt)

```bash
# Установка certbot
apt-get install certbot python3-certbot-apache  # для Apache
# или
apt-get install certbot python3-certbot-nginx   # для Nginx

# Получение сертификата
certbot --apache -d your-domain.com  # для Apache
# или
certbot --nginx -d your-domain.com   # для Nginx

# Автоматическое обновление
certbot renew --dry-run
```

---

## Регистрация приложения в Bitrix24

### 1. Создание локального приложения

1. Войдите в Bitrix24
2. Перейдите: **Приложения** → **Разработчикам** → **Другое** → **Локальное приложение**
3. Заполните:
   - **Название**: Bitrix Multi-Messenger Integration
   - **Код**: bitrix_messenger_integration
   - **URL установки**: `https://your-domain.com/install.php`
   - **URL активации**: `https://your-domain.com/activate.php`

### 2. Настройка прав доступа

Необходимые права:
- `imconnector` - Для работы с коннекторами
- `im` - Для работы с открытыми линиями
- `crm` - Для доступа к CRM (опционально)

### 3. Получение Client ID и Client Secret

После создания приложения:
1. Скопируйте **Client ID** и **Client Secret**
2. Добавьте их в `.env` файл:

```env
BITRIX_CLIENT_ID=local.xxxxxxxxx.xxxxxxxx
BITRIX_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

---

## Настройка мессенджеров

### Telegram

1. Создайте бота через [@BotFather](https://t.me/BotFather)
2. Получите токен бота
3. Добавьте в базу данных:

```bash
curl -X POST https://your-domain.com/add_token_telegram.php \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "your-company.bitrix24.ru",
    "telegram_bot_token": "YOUR_BOT_TOKEN"
  }'
```

4. Установите webhook:

```bash
curl "https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook?url=https://your-domain.com/webhook.php"
```

### Max

1. Получите API токен в панели Max
2. Добавьте в базу данных:

```bash
curl -X POST https://your-domain.com/add_token_max.php \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "your-company.bitrix24.ru",
    "api_token_max": "YOUR_MAX_TOKEN"
  }'
```

3. Настройте webhook:

```bash
curl -X POST https://your-domain.com/subscribe.php \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "your-company.bitrix24.ru"
  }'
```

---

## Проверка работоспособности

### 1. Проверка подключения к БД

```bash
php -r "
require 'vendor/autoload.php';
\$config = require 'config/config.php';
try {
    \$db = new PDO(
        'mysql:host='.\$config['database']['host'].';dbname='.\$config['database']['name'],
        \$config['database']['user'],
        \$config['database']['password']
    );
    echo 'Database connection: OK';
} catch (PDOException \$e) {
    echo 'Database connection: FAILED - ' . \$e->getMessage();
}
"
```

### 2. Проверка прав доступа

```bash
cd /var/www/bitrix-telegram-integration
ls -la logs/
# Должно быть: drwxrwxr-x ... www-data www-data
```

### 3. Проверка URL

```bash
curl https://your-domain.com/install.php
# Должна вернуться HTML страница
```

### 4. Тест вебхука

```bash
curl -X POST https://your-domain.com/webhook.php \
  -H "Content-Type: application/json" \
  -d '{
    "update_id": 1,
    "message": {
      "message_id": 1,
      "from": {"id": 123, "first_name": "Test"},
      "chat": {"id": 123, "type": "private"},
      "text": "/start"
    }
  }'
```

---

## Мониторинг и логирование

### Настройка ротации логов

```bash
nano /etc/logrotate.d/bitrix-integration
```

```
/var/www/bitrix-telegram-integration/logs/*.txt {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0664 www-data www-data
    sharedscripts
}
```

### Мониторинг логов в реальном времени

```bash
tail -f /var/www/bitrix-telegram-integration/logs/$(date +%Y-%m-%d).txt
```

---

## Обновление приложения

### Стандартное обновление

```bash
cd /var/www/bitrix-telegram-integration

# Резервное копирование
tar -czf backup-$(date +%Y%m%d).tar.gz .

# Получение обновлений
git pull origin main

# Обновление зависимостей
composer install --no-dev

# Очистка кеша (если есть)
rm -rf cache/*

# Проверка миграций БД (если нужно)
# php migrate.php
```

### Откат к предыдущей версии

```bash
# Восстановление из резервной копии
cd /var/www/
rm -rf bitrix-telegram-integration
tar -xzf backup-20240120.tar.gz
chown -R www-data:www-data bitrix-telegram-integration
```

---

## Резервное копирование

### Скрипт автоматического бэкапа

```bash
nano /usr/local/bin/backup-bitrix-integration.sh
```

```bash
#!/bin/bash
BACKUP_DIR="/backups/bitrix-integration"
APP_DIR="/var/www/bitrix-telegram-integration"
DATE=$(date +%Y%m%d_%H%M%S)

# Создание директории
mkdir -p $BACKUP_DIR

# Бэкап файлов
tar -czf $BACKUP_DIR/files-$DATE.tar.gz -C $APP_DIR .

# Бэкап БД
mysqldump -u bitrix_user -p'your_password' bitrix_telegram_integration > $BACKUP_DIR/db-$DATE.sql
gzip $BACKUP_DIR/db-$DATE.sql

# Удаление старых бэкапов (старше 30 дней)
find $BACKUP_DIR -type f -mtime +30 -delete

echo "Backup completed: $DATE"
```

Добавьте в cron:
```bash
chmod +x /usr/local/bin/backup-bitrix-integration.sh
crontab -e
```

```cron
# Ежедневный бэкап в 3:00
0 3 * * * /usr/local/bin/backup-bitrix-integration.sh >> /var/log/bitrix-backup.log 2>&1
```

---

## Безопасность

### 1. Защита .env файла

```bash
chmod 600 .env
chown www-data:www-data .env
```

### 2. Настройка firewall

```bash
# UFW
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable

# iptables
iptables -A INPUT -p tcp --dport 22 -j ACCEPT
iptables -A INPUT -p tcp --dport 80 -j ACCEPT
iptables -A INPUT -p tcp --dport 443 -j ACCEPT
iptables -A INPUT -j DROP
```

### 3. Ограничение доступа к служебным файлам

В `.htaccess` (Apache) или конфигурации Nginx:

```apache
# Apache
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>

<FilesMatch "(composer\.(json|lock)|\.env)">
    Order allow,deny
    Deny from all
</FilesMatch>
```

```nginx
# Nginx
location ~ /\. {
    deny all;
}

location ~ (composer\.(json|lock)|\.env) {
    deny all;
}
```

---

## Production Checklist

- [ ] SSL сертификат установлен и работает
- [ ] `.env` файл настроен корректно
- [ ] `APP_DEBUG=false` в production
- [ ] Права доступа настроены правильно
- [ ] База данных создана и таблицы установлены
- [ ] Приложение зарегистрировано в Bitrix24
- [ ] Токены мессенджеров добавлены
- [ ] Вебхуки настроены
- [ ] Логирование работает
- [ ] Backup настроен
- [ ] Firewall настроен
- [ ] Мониторинг настроен
- [ ] Тестовые сообщения отправляются

---

## Поддержка и помощь

При возникновении проблем:

1. Проверьте логи: `/var/www/bitrix-telegram-integration/logs/`
2. Проверьте логи веб-сервера: `/var/log/apache2/` или `/var/log/nginx/`
3. Проверьте системные логи: `/var/log/syslog`
4. Обратитесь к документации: `docs/TROUBLESHOOTING.md`