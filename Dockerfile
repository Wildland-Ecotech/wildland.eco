# Pinned to an exact PHP release so security fixes reach every environment:
# deploys run `docker compose build` without --pull, so a floating tag such as
# 8.5-fpm can keep using an older cached image. Update the version when
# php.net announces a release; see "Updating PHP" in docs/07-deployment.md.
FROM php:8.5.12-fpm

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

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www
