FROM php:8.3-fpm

# المكتبات اللي يحتاجها composer
RUN apt-get update && apt-get install -y git unzip libzip-dev \
    && docker-php-ext-install zip pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .

RUN composer install --no-dev --optimize-autoloader