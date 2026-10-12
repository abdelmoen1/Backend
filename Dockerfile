FROM php:8.3-fpm

# المكتبات اللي يحتاجها composer
RUN apt-get update && apt-get install -y git unzip libzip-dev \
    && docker-php-ext-install zip pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .

RUN composer install --no-dev --optimize-autoloader

# سجلات Laravel تُكتب أيضًا إلى stderr لتظهر في سجلات منصة الاستضافة؛ ملف السجل داخل الحاوية
# لا يمكن قراءته بدون صلاحية shell. أي قيمة LOG_STACK في إعدادات المنصة تتقدم على هذه القيمة.
ENV LOG_STACK=single,stderr

CMD php artisan serve --host=0.0.0.0 --port=${PORT:-8080}