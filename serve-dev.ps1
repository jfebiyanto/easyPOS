Set-Location D:\Devs\easyPOS\public
$env:PHPRC = 'D:\Devs\easyPOS\php.ini'
php -S 127.0.0.1:8110 -t . ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php *> ..\serve.log