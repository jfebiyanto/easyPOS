#!/bin/sh
# Jalankan di VPS dari dalam folder bundel deploy:  ./install.sh
# Memuat image, memulihkan database + upload gambar (hanya saat pertama kali), lalu menjalankan stack.
set -e
cd "$(dirname "$0")"

[ -f .env.docker ] || { echo "File .env.docker tidak ditemukan."; exit 1; }
[ -f easypos-images.tar ] || { echo "File easypos-images.tar tidak ditemukan."; exit 1; }

echo "[1/4] memuat image..."
docker load -i easypos-images.tar

DC="docker compose --env-file .env.docker -f docker-compose.prod.yml"

echo "[2/4] menjalankan database (dump dipulihkan otomatis bila volume masih kosong)..."
$DC up -d postgres
$DC up -d --wait postgres

if [ -f uploads.tar.gz ]; then
    echo "[3/4] memulihkan upload gambar (produk, QR)..."
    $DC run --rm --no-deps --entrypoint sh -v "$(pwd)/uploads.tar.gz:/uploads.tar.gz:ro" app -c \
        'mkdir -p /var/www/storage/app/public && tar xzf /uploads.tar.gz -C /var/www/storage/app/public && chown -R www-data:www-data /var/www/storage'
else
    echo "[3/4] tidak ada uploads.tar.gz, dilewati."
fi

echo "[4/4] menjalankan aplikasi..."
$DC up -d

echo "Selesai. Cek status: $DC ps"
