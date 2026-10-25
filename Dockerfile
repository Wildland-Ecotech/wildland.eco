# Builds the images every environment runs. Staging and production run the
# `app` and `nginx` images, which contain one commit's code, PHP dependencies,
# and built assets, so a running site never changes until its containers are
# replaced. Local development runs the `dev` image with the code mounted from
# the host instead (docker-compose.dev.yml). See docs/07-deployment.md.

# Pinned to an exact PHP release so security fixes reach every environment:
# deploys run `docker compose build` without --pull, so a floating tag such as
# 8.5-fpm can keep using an older cached image. Update the version when
# php.net announces a release; see "Updating PHP" in docs/07-deployment.md.
FROM php:8.5.12-fpm AS php-base

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libzip-dev \
    libonig-dev \
    libpq-dev \
    zip \
    unzip

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_pgsql mbstring bcmath zip \
 && pecl install redis \
 && docker-php-ext-enable redis

# Custom PHP configuration
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www

# Local development: PHP and Composer, with the code mounted from the host
FROM php-base AS dev

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# PHP dependencies, without dev packages. The lock files are copied first, so
# this layer is rebuilt only when dependencies change.
FROM php-base AS vendor

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/laravel

COPY laravel/composer.json laravel/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY laravel/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts

# Frontend assets, built into public/build
FROM node:24-slim AS assets

WORKDIR /var/www/laravel

COPY laravel/package.json laravel/package-lock.json ./
RUN npm ci

COPY laravel/ ./
RUN npm run build

# Staging and production: PHP-FPM with the code baked in. Used by the app,
# queue, and scheduler containers.
FROM php-base AS app

# The user the containers run as (UID and GID in the root .env), which owns
# only the directories Laravel writes to. The code itself stays owned by root,
# so the application cannot change its own files.
ARG UID=1000
ARG GID=1000

WORKDIR /var/www/laravel

COPY --from=vendor /var/www/laravel ./
COPY --from=assets /var/www/laravel/public/build ./public/build

RUN php artisan package:discover --ansi \
 && chown -R "${UID}:${GID}" storage bootstrap/cache

# Compiled Blade views stay inside each container rather than in the shared
# storage volume, so a new release never reads views compiled for the old one
ENV VIEW_COMPILED_PATH=/tmp/views

COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/laravel-entrypoint
ENTRYPOINT ["laravel-entrypoint"]
CMD ["php-fpm"]

# Staging and production: nginx with the public files, including the built
# assets, from the same build as the app image
FROM nginx:alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /var/www/laravel/public /var/www/laravel/public
COPY --from=assets /var/www/laravel/public/build /var/www/laravel/public/build
