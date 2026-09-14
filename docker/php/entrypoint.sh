#!/bin/sh
set -e

echo "[easypos] menunggu database..."
until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Exception $e) { exit(1); }'; do
    sleep 2
done

echo "[easypos] menjalankan migrasi..."
php artisan migrate --force

# Seeder hanya berjalan bila diminta eksplisit (RUN_SEEDER=true) agar
# kredensial default tidak pernah terbuat otomatis di produksi.
if [ "${RUN_SEEDER:-false}" = "true" ] || [ "${RUN_SEEDER:-false}" = "1" ]; then
    echo "[easypos] seeding (RUN_SEEDER aktif)..."
    php artisan db:seed --force
fi

php artisan config:clear

echo "[easypos] siap. menjalankan php-fpm..."
exec php-fpm