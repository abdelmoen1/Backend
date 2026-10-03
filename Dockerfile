# استخدام نسخة PHP مستقرة وخفيفة تناسب لارافل 11 و 12
FROM php:8.3-fpm-alpine

# تثبيت الأدوات والإضافات الأساسية للسيرفر وقاعدة البيانات
RUN apk add --no-cache nginx wget supervisor mysql-client curl
RUN docker-php-ext-install pdo pdo_mysql

# تحديد مجلد العمل داخل حاوية الدوكر
WORKDIR /var/www/html

# نسخ ملفات المشروع بالكامل
COPY . .

# تثبيت حزم الملحق (Composer) للمشروع بشكل صحيح
RUN curl -sS https://getcomposer.org | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader


# تعيين الصلاحيات الصحيحة لمجلدات لارافل لتجنب مشاكل التخزين المؤقت
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# إنشاء ملف إعدادات Nginx لتوجيه مسار الموقع إلى مجلد public الخاص بلارافل
RUN echo 'server { \
    listen 80; \
    root /var/www/html/public; \
    index index.php index.html; \
    location / { try_files $uri $uri/ /index.php?$query_string; } \
    location ~ \.php$ { \
        try_files $uri =404; \
        fastcgi_split_path_info ^(.+\.php)(/.+)$; \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_index index.php; \
        include fastcgi_params; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_param; \
        fastcgi_param PATH_INFO $fastcgi_path_info; \
    } \
}' > /etc/nginx/http.d/default.conf

# تشغيل الـ PHP وسيرفر Nginx معاً عند إقلاع الحاوية
CMD php-fpm -D && nginx -g "daemon off;"
