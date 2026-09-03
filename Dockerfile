# Sistem SUSENAS — image PHP-FPM untuk deployment.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev icu-dev oniguruma-dev libzip-dev $PHPIZE_DEPS \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql intl mbstring zip bcmath opcache \
 && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# dependency layer (di-cache selama composer.* tak berubah)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# kode aplikasi
COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && cp docker/php.ini "$PHP_INI_DIR/conf.d/zz-susenas.ini" \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
