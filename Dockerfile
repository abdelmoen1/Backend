FROM php:8.3-apache

# مكتبات الامتدادات: zip لـ composer، و pdo_mysql و pdo_pgsql حتى يكون محرك قاعدة البيانات إعدادًا لا إعادة بناء
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpq-dev libicu-dev \
    && docker-php-ext-install zip pdo_mysql pdo_pgsql intl bcmath opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache يخدم public/ فقط ويستمع على PORT الذي تحدده المنصة (Render يمرر 10000 افتراضيًا)
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf
COPY docker/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini

WORKDIR /var/www/html

# تثبيت الاعتماديات قبل نسخ الكود حتى تُعاد طبقة composer فقط عند تغير composer.lock
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_STACK=stderr

EXPOSE 10000

ENTRYPOINT ["docker/entrypoint.sh"]
