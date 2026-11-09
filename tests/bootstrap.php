<?php

/**
 * Bootstrap PHPUnit: kunci env tes sebelum aplikasi boot.
 *
 * Env proses (mis. APP_ENV=local, DB_CONNECTION=pgsql dari shell dev) tidak ditimpa oleh <env> di
 * phpunit.xml dan dibaca Laravel lebih dulu lewat $_SERVER, sehingga RefreshDatabase bisa menjalankan
 * migrate:fresh pada database dev. Nilai di bawah selalu menang.
 */
$locked = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
];

foreach ($locked as $name => $value) {
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv("{$name}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
