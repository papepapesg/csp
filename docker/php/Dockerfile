# syntax=docker/dockerfile:1
#
# SOPHIX BSS — PHP-FPM application image (FOUNDATION runtime).
# Multi-stage: PHP deps → frontend assets (need vendor/tightenco/ziggy) → vendor+autoload → runtime.

# ---------- Stage 1: PHP composer packages ----------
# Produces vendor/ (incl. tightenco/ziggy, which resources/js/app.js imports at build time).
FROM composer:2 AS phpdeps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
        --no-interaction --ignore-platform-reqs

# ---------- Stage 2: frontend assets (Inertia + Vue + Vite) ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* vite.config.js ./
RUN npm ci || npm install
COPY resources ./resources
COPY public ./public
COPY vite-module-loader.js ./
COPY Modules ./Modules
# Ziggy ships as a Composer package but is imported by the JS build — bring it in first.
COPY --from=phpdeps /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN npm run build

# ---------- Stage 3: PHP application + optimized autoloader ----------
FROM php:8.4-fpm-bookworm AS vendor
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev libpng-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath gd pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY --from=phpdeps /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev

# ---------- Stage 4: runtime ----------
FROM php:8.4-fpm-bookworm AS runtime
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq5 libicu72 libzip4 libpng16-16 postgresql-client \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath gd pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-sophix.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html
COPY --from=vendor /var/www/html /var/www/html
RUN chown -R www-data:www-data storage bootstrap/cache \
    && mkdir -p storage/framework/{sessions,views,cache} storage/logs

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
