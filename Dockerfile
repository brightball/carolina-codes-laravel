FROM php:8.5-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev unzip git \
 && docker-php-ext-install pdo_pgsql pcntl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader
COPY . .
RUN cp -n .env.example .env \
 && php artisan key:generate --force \
 && php artisan package:discover --ansi
ENV PORT=8080
ENV APP_ENV=production
EXPOSE 8080
CMD ["php", "artisan", "carolina:serve", "--host=[::]", "--port=8080"]
