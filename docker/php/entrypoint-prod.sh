#!/bin/sh
set -e

# Peran container ditentukan oleh perintahnya: php-fpm (web), schedule:work (scheduler), queue:work (worker).
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

if [ "$1" = "php-fpm" ]; then
    echo "[easypos] menunggu database..."
    until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Exception $e) { exit(1); }'; do
        sleep 2
    done

    echo "[easypos] migrasi (data yang ada tidak disentuh)..."
    runuser -u www-data -- php artisan migrate --force

    runuser -u www-data -- php artisan config:cache
    runuser -u www-data -- php artisan route:cache || true
    runuser -u www-data -- php artisan view:cache || true

    touch /tmp/ready
    echo "[easypos] siap. menjalankan php-fpm..."
    exec php-fpm
fi

exec runuser -u www-data -- "$@"
