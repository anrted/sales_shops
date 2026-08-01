FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    bash \
    curl \
    git \
    docker-cli \
    docker-cli-compose \
    postgresql-dev \
    icu-dev \
    libzip-dev \
    unzip \
    zip \
    && docker-php-ext-install intl pdo pdo_pgsql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

# Кешируем зависимости Composer
COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-dev

# Копируем остальной код проекта (для production)
COPY backend .

# Генерируем оптимизированный автозагрузчик
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/backend \
    && chmod -R 775 /var/www/backend/storage /var/www/backend/bootstrap/cache
