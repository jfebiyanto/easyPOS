FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev zip unzip git libonig-dev libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql mbstring zip opcache bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache \
    && sed -i 's/\r$//' docker/php/entrypoint.sh \
    && chmod +x docker/php/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker/php/entrypoint.sh"]