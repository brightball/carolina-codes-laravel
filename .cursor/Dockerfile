FROM php:8.5-cli
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends git curl ca-certificates libpq-dev unzip \
 && docker-php-ext-install pdo_pgsql pcntl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
