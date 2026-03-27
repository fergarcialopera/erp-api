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

WORKDIR /var/www/html
