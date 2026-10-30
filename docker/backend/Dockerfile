FROM php:8.4-fpm-alpine

RUN apk add --no-cache git unzip icu-dev libzip-dev postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql intl zip bcmath pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dev: mã nguồn được mount từ máy host (xem docker-compose.yml).
# Production sẽ có Dockerfile/stage riêng (COPY mã nguồn, composer install --no-dev) — xem docs/DEPLOYMENT.md.
