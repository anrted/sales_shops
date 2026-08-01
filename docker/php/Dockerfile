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
RUN composer install --no-scripts --no-autoloader --prefer-dist

# Копируем остальной код проекта
COPY backend .

# Подготавливаем директории и права перед генерацией автозагрузчика
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && chmod -R 777 storage bootstrap/cache \
    && composer dump-autoload --optimize

# Копируем entrypoint скрипт автоматической инициализации
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
