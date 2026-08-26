FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    bash \
    git \
    postgresql-dev \
    libzip-dev \
    unzip \
    php84-pecl-redis \
    && docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
