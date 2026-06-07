# syntax=docker/dockerfile:1
#
# SOPHIX BSS — PHP-FPM application image (FOUNDATION runtime).
# Multi-stage: build frontend assets with Node, install PHP deps, run on PHP-FPM.

# ---------- Stage 1: frontend assets (Inertia + Vue + Vite) ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* vite.config.js ./
RUN npm ci || npm install
COPY resources ./resources
COPY public ./public
COPY vite-module-loader.js ./
COPY Modules ./Modules
RUN npm run build

# ---------- Stage 2: PHP dependencies ----------
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
COPY composer.json composer.lock ./
# Install without scripts/autoloader first (source not yet copied), full dump after app copy.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev

# ---------- Stage 3: runtime ----------
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
