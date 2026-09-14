<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

// Autentikasi API (token Sanctum)
Route::post('/auth/login', [Api\AuthController::class, 'login'])->middleware('throttle:login');

// Webhook payment gateway (diverifikasi lewat callback token provider).
Route::post('/webhooks/payments/xendit', [Api\PaymentWebhookController::class, 'xendit']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [Api\AuthController::class, 'logout']);
    Route::get('/auth/me', [Api\AuthController::class, 'me']);

    // Semua endpoint berikut hanya untuk user Admin (aktif)
    Route::middleware('admin')->group(function () {
        // Stores — write hanya superuser
        Route::get('/stores', [Api\StoreApiController::class, 'index']);
        Route::get('/stores/{store}', [Api\StoreApiController::class, 'show'])->whereUuid('store');
        Route::post('/stores', [Api\StoreApiController::class, 'store']);
        Route::put('/stores/{store}', [Api\StoreApiController::class, 'update'])->whereUuid('store');
        Route::delete('/stores/{store}', [Api\StoreApiController::class, 'destroy'])->whereUuid('store');

        // Users
        Route::get('/users', [Api\UserApiController::class, 'index']);
        Route::get('/users/{user}', [Api\UserApiController::class, 'show'])->whereUuid('user');
        Route::post('/users', [Api\UserApiController::class, 'store']);
        Route::put('/users/{user}', [Api\UserApiController::class, 'update'])->whereUuid('user');
        Route::post('/users/{user}/reset-password', [Api\UserApiController::class, 'resetPassword'])->whereUuid('user');
        Route::delete('/users/{user}', [Api\UserApiController::class, 'destroy'])->whereUuid('user');

        // Product groups — write hanya superuser
        Route::get('/product-groups', [Api\ProductGroupApiController::class, 'index']);
        Route::get('/product-groups/{group}', [Api\ProductGroupApiController::class, 'show'])->whereUuid('group');
        Route::post('/product-groups', [Api\ProductGroupApiController::class, 'store']);
        Route::put('/product-groups/{group}', [Api\ProductGroupApiController::class, 'update'])->whereUuid('group');
        Route::delete('/product-groups/{group}', [Api\ProductGroupApiController::class, 'destroy'])->whereUuid('group');

        // Brands
        Route::get('/brands', [Api\BrandApiController::class, 'index']);
        Route::get('/brands/{brand}', [Api\BrandApiController::class, 'show'])->whereUuid('brand');
        Route::post('/brands', [Api\BrandApiController::class, 'store']);
        Route::put('/brands/{brand}', [Api\BrandApiController::class, 'update'])->whereUuid('brand');
        Route::delete('/brands/{brand}', [Api\BrandApiController::class, 'destroy'])->whereUuid('brand');

        // Products
        Route::get('/products', [Api\ProductApiController::class, 'index']);
        Route::get('/products/{product}', [Api\ProductApiController::class, 'show'])->whereUuid('product');
        Route::post('/products', [Api\ProductApiController::class, 'store']);
        Route::put('/products/{product}', [Api\ProductApiController::class, 'update'])->whereUuid('product');
        Route::delete('/products/{product}', [Api\ProductApiController::class, 'destroy'])->whereUuid('product');

        // Price lists + item harga — write hanya superuser
        Route::get('/price-lists', [Api\PriceListApiController::class, 'index']);
        Route::get('/price-lists/{priceList}', [Api\PriceListApiController::class, 'show'])->whereUuid('priceList');
        Route::get('/price-lists/{priceList}/items', [Api\PriceListApiController::class, 'items'])->whereUuid('priceList');
        Route::post('/price-lists', [Api\PriceListApiController::class, 'store']);
        Route::put('/price-lists/{priceList}', [Api\PriceListApiController::class, 'update'])->whereUuid('priceList');
        Route::post('/price-lists/{priceList}/items', [Api\PriceListApiController::class, 'storeItem'])->whereUuid('priceList');
        Route::delete('/price-lists/{priceList}/items/{item}', [Api\PriceListApiController::class, 'destroyItem'])
            ->whereUuid(['priceList', 'item']);
        Route::delete('/price-lists/{priceList}', [Api\PriceListApiController::class, 'destroy'])->whereUuid('priceList');

        // Promotions
        Route::get('/promotions', [Api\PromotionApiController::class, 'index']);
        Route::get('/promotions/{promotion}', [Api\PromotionApiController::class, 'show'])->whereUuid('promotion');
        Route::post('/promotions', [Api\PromotionApiController::class, 'store']);
        Route::put('/promotions/{promotion}', [Api\PromotionApiController::class, 'update'])->whereUuid('promotion');
        Route::delete('/promotions/{promotion}', [Api\PromotionApiController::class, 'destroy'])->whereUuid('promotion');

        // Penomoran transaksi
        Route::get('/numberings', [Api\NumberingApiController::class, 'index']);
        Route::put('/numberings', [Api\NumberingApiController::class, 'update']);
        Route::post('/numberings/{type}/next', [Api\NumberingApiController::class, 'next'])->where('type', '[a-z_]+');

        // Activity log
        Route::get('/activity-logs', [Api\ActivityLogApiController::class, 'index']);
    });
});
