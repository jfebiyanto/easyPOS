<?php

use App\Http\Middleware\EnsurePosSessionIsActive;
use App\Http\Middleware\EnsurePosStoreIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'pos.store' => EnsurePosStoreIsActive::class,
            'pos.session' => EnsurePosSessionIsActive::class,
        ]);

        // Cookie identitas perangkat POS berisi token acak, bukan data sensitif; dibaca apa adanya.
        $middleware->encryptCookies(except: ['pos_device']);

        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
