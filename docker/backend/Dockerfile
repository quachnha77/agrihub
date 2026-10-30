FROM php:8.4-fpm-alpine

# postgresql16-client: pg_dump/pg_restore cho sao lưu toàn hệ thống. Phải CÙNG phiên bản chính với image postgres trong
# docker-compose (pg_dump mới hơn máy chủ tạo ra bản dump mà máy chủ cũ không nạp được).
RUN apk add --no-cache git unzip icu-dev libzip-dev postgresql-dev postgresql16-client $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql intl zip bcmath pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Giới hạn tải lên cho Import sao lưu (khớp BACKUP_MAX_UPLOAD_MB). Image không có php.ini nên mặc định chỉ 2 MB.
COPY php-agrihub.ini /usr/local/etc/php/conf.d/zz-agrihub.ini

WORKDIR /var/www/html

# Dev: mã nguồn được mount từ máy host (xem docker-compose.yml).
# Production sẽ có Dockerfile/stage riêng (COPY mã nguồn, composer install --no-dev) — xem docs/DEPLOYMENT.md.
