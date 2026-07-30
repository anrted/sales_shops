# Инструкция по развертыванию Discount Hub

В этом руководстве описан процесс быстрого развертывания проекта на новом сервере (VPS) под управлением Ubuntu/Debian.

## Системные требования

Перед началом установки убедитесь, что на вашем сервере установлены следующие компоненты:
- **Git** (для клонирования репозитория)
- **Docker** и **Docker Compose**
- **Node.js** и **npm** (требуются для скрипта обновления сессий Lenta, который использует браузер Playwright)

Если у вас чистый сервер Ubuntu/Debian, вы можете установить Docker и Node.js (v20) следующими командами:
```bash
# Установка Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Установка Node.js и npm
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
```

## Установка и запуск

1. **Клонируйте репозиторий:**
   ```bash
   git clone https://github.com/anrted/sales_shops.git discounts
   cd discounts
   ```

2. **Запустите скрипт автоматической установки:**
   Скрипт проверит наличие необходимых зависимостей, установит пакеты браузера Playwright, создаст конфигурационные файлы `.env` и запустит Docker контейнеры.
   ```bash
   chmod +x install.sh
   ./install.sh
   ```

3. **Настройка окружения (Опционально):**
   Во время установки скрипт скопирует базовые настройки из `.env.example` в `.env`. Если вам нужно изменить конфигурацию внешних API, базы данных или Redis, отредактируйте файлы:
   - `backend/.env`
   - `frontend/.env`

   После изменения `.env` файлов перезапустите контейнеры:
   ```bash
   docker compose restart
   ```

## Настройка Cron (Обязательно для парсинга)

Для стабильной работы парсера Ленты необходимо ежедневно обновлять сессионные куки. Для этого добавьте задачу в cron.

Откройте редактор cron:
```bash
crontab -e
```

Добавьте следующую строку, указав абсолютный путь до папки `backend` вашего проекта:
```cron
# Запуск обновления сессии Lenta каждую ночь в 03:15
15 3 * * * cd /АБСОЛЮТНЫЙ/ПУТЬ/К/ПРОЕКТУ/backend && /usr/bin/php artisan lenta:refresh-session >> /АБСОЛЮТНЫЙ/ПУТЬ/К/ПРОЕКТУ/backend/storage/logs/lenta-session-refresh.log 2>&1
```

## Настройка веб-сервера (Nginx)

По умолчанию Docker-контейнеры публикуют следующие порты на `localhost`:
- Frontend (Nuxt 3): `http://127.0.0.1:3000`
- Backend API (Laravel): `http://127.0.0.1:8080/api`

Рекомендуется настроить Nginx в качестве reverse-proxy, чтобы перенаправлять трафик с вашего домена 80/443 порта на эти локальные порты.

Пример конфигурации Nginx (`/etc/nginx/sites-available/discounts`):
```nginx
server {
    listen 80;
    server_name your-domain.com;

    # Backend API
    location /api {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Frontend
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```
