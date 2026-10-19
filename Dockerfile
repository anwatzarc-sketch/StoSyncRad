# syntax=docker/dockerfile:1

# StoSyncRad production image: PHP 8.3 + Apache serving public/, production Composer dependencies and
# the Vite-built frontend. No configuration is baked in: supply it at runtime by mounting a .env file
# at /var/www/html/.env or by passing environment variables.
#
# One image runs every process role; choose it with the container command:
#   web (default)  apache2-foreground                                        (listens on 8080)
#   queue worker   php artisan queue:work --queue=imports,reporting,default
#   scheduler      php artisan schedule:work
#   release        php artisan migrate --database=pgsql_deploy --force, then php artisan platform:upgrade
#
# Mount one volume at /var/www/html/storage and share it between the web and worker containers:
# import uploads, report exports and brand assets cross between them. Run worker and scheduler
# containers with stop signal SIGTERM; the base image's SIGWINCH only stops Apache gracefully.

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

# --- Frontend build (Node is needed at build time only) -------------------------------------------
FROM node:${NODE_VERSION}-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund

COPY vite.config.js tsconfig.json ./
COPY resources ./resources
RUN npm run build

# --- Application ----------------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache-bookworm

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The base image already ships mbstring, openssl, fileinfo, ctype, json, xml, tokenizer and curl. Add
# the rest of the platform preflight's list, gd for brand icons and PDFs, and pcntl for worker signals.
RUN install-php-extensions pdo_pgsql pgsql intl zip bcmath gd opcache pcntl

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY <<'EOF' /usr/local/etc/php/conf.d/zz-stosyncrad.ini
expose_php = Off
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 128M
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 20000
; Code never changes inside a running container.
opcache.validate_timestamps = 0
EOF

# Serve public/ on unprivileged port 8080 so Apache can run as www-data.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/server-name.conf \
    && a2enconf server-name \
    && a2enmod rewrite

WORKDIR /var/www/html

# Dependencies first, so this layer is reused until composer.lock changes.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
COPY --from=assets /app/public/build ./public/build

# Application code stays root-owned and read-only; only storage/ and bootstrap/cache are writable.
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan storage:link \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=755 <<'EOF' /usr/local/bin/stosyncrad-entrypoint
#!/bin/sh
set -e

# storage/ is normally a mounted volume: recreate Laravel's directory layout when it starts out empty.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs

# Cache config, events, routes and views from the runtime environment.
php artisan optimize

exec "$@"
EOF

USER www-data

EXPOSE 8080

ENTRYPOINT ["stosyncrad-entrypoint"]
CMD ["apache2-foreground"]
