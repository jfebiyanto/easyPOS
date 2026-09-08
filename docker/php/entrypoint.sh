#!/bin/sh
set -e

echo "[easypos] menunggu database..."
until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Exception $e) { exit(1); }'; do
    sleep 2
done

echo "[easypos] menjalankan migrasi..."
php artisan migrate --force

echo "[easypos] seed hanya jika database masih kosong..."
COUNT=$(php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null | tail -n 1)
if [ "$COUNT" = "0" ] || [ -z "$COUNT" ]; then
    php artisan db:seed --force
fi

php artisan config:clear

echo "[easypos] siap. menjalankan php-fpm..."
exec php-fpm