# ventMail app konténer — PHP 8.3 (a spec LOCKED verziója), FPM.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev linux-headers $PHPIZE_DEPS icu-dev libzip-dev netcat-openbsd \
    && docker-php-ext-install pdo_pgsql pcntl bcmath intl zip sockets \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
