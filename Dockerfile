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
# Classmap is generated after sources land. Caching resolved config in this
# build would freeze Fly-injected runtime env (database URL, app key, ports).
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
 && cp -n .env.example .env \
 && php artisan package:discover --ansi \
 && php artisan key:generate --force \
 && printf '%s\n' \
      'opcache.enable=1' \
      'opcache.enable_cli=1' \
      'opcache.validate_timestamps=0' \
      'opcache.memory_consumption=128' \
      'opcache.max_accelerated_files=20000' \
      'opcache.interned_strings_buffer=16' \
      'opcache.file_cache=/var/tmp/opcache' \
      'realpath_cache_size=4096K' \
      'realpath_cache_ttl=600' \
      > /usr/local/etc/php/conf.d/opcache-cli.ini \
 && mkdir -p /var/tmp/opcache
ENV PORT=8080
ENV APP_ENV=production
EXPOSE 8080
CMD ["php", "artisan", "carolina:serve", "--host=[::]", "--port=8080"]
