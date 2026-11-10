#!/bin/sh
# Perbarui aplikasi di VPS dari git. Taruh file ini di folder deploy (satu folder dengan docker-compose.prod.yml)
# dan jalankan dari sana:  ./update.sh
# Kode diambil dari ../src (hasil `git clone` repo). Data (database, upload) tidak disentuh.
set -e
cd "$(dirname "$0")"
DEPLOY_DIR="$(pwd)"
SRC_DIR="${SRC_DIR:-$DEPLOY_DIR/../src}"

[ -d "$SRC_DIR/.git" ] || { echo "Folder kode $SRC_DIR belum ada. Jalankan: git clone <URL-REPO> $SRC_DIR"; exit 1; }
[ -f .env.docker ] || { echo "File .env.docker tidak ditemukan di $DEPLOY_DIR."; exit 1; }

echo "[1/4] mengambil kode terbaru..."
git -C "$SRC_DIR" pull --ff-only

echo "[2/4] membangun image app..."
docker build -f "$SRC_DIR/docker/Dockerfile.prod" --target app -t easypos-app:demo "$SRC_DIR"

echo "[3/4] membangun image web..."
docker build -f "$SRC_DIR/docker/Dockerfile.prod" --target web -t easypos-web:demo "$SRC_DIR"

echo "[4/4] menerapkan (migrasi database berjalan otomatis saat app start)..."
docker compose --env-file .env.docker -f docker-compose.prod.yml up -d

docker compose --env-file .env.docker -f docker-compose.prod.yml ps
echo "Selesai."
