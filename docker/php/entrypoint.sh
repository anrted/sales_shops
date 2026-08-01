#!/bin/bash
set -e

cd /var/www/backend

# 1. Create .env if missing
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        echo "[ENTRYPOINT] Created .env from .env.example"
    fi
fi

# 2. Fix permissions
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 777 storage bootstrap/cache 2>/dev/null || true

# 3. Check APP_KEY and generate if missing
if [ -f .env ]; then
    if ! grep -qE '^APP_KEY=base64:' .env; then
        echo "[ENTRYPOINT] APP_KEY is missing or empty. Generating key..."
        php artisan key:generate --force
    fi
fi

# 4. Clear old caches
php artisan config:clear || true
php artisan cache:clear || true

# 5. Run database migrations (only in main backend container if command is php-fpm)
if [ "$1" = "php-fpm" ]; then
    echo "[ENTRYPOINT] Running database migrations..."
    php artisan migrate --force || echo "[ENTRYPOINT WARNING] Migration failed, proceeding..."
fi

exec "$@"
