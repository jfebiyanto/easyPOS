# Jalankan easyPOS dev server dengan php.ini project (pdo_pgsql aktif).
# Pemakaian: hentikan server lama, lalu jalankan  .\serve.ps1
$env:PHPRC = 'D:\Devs\easyPOS\php.ini'
Set-Location 'D:\Devs\easyPOS'
php artisan serve --host=127.0.0.1 --port=8000
