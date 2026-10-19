# PHP-FPM image for the Laravel API (local development and CI).
FROM php:8.4.26-fpm-alpine

# System libraries needed to compile the PHP extensions below.
RUN apk add --no-cache icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
    && docker-php-ext-install pdo_mysql bcmath intl zip opcache pcntl \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Upload limits for Settings → Import (CSV files up to 50 MB).
COPY uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

# Composer binary copied from the official image (pinned).
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

# Run as the same UID/GID as the host user so files created in the
# bind-mounted source tree stay owned by you, not root.
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app
USER app

WORKDIR /var/www/backend
