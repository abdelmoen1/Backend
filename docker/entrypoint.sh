#!/bin/sh
# تشغيل الحاوية: تجهيز ذاكرة الإعدادات ثم (اختياريًا) الترحيلات والبيانات المرجعية ثم Apache.
# فشل الترحيل يُنهي الحاوية فيفشل النشر على Render ويبقى الإصدار السابق قيد التشغيل.
set -e

cd /var/www/html

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link --force >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[entrypoint] running database migrations"
    php artisan migrate --force --no-interaction
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    # المُنشئات idempotent (firstOrCreate/updateOrCreate) وبيانات العرض تُتخطى في الإنتاج
    echo "[entrypoint] seeding reference data"
    php artisan db:seed --force --no-interaction
fi

exec apache2-foreground
