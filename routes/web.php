<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'admin'])
    ->group(function () {
        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

        // Store management (HO can manage multiple stores)
        Route::get('/stores/switch/{store}', [Admin\StoreController::class, 'switch'])->name('stores.switch');
        Route::resource('stores', Admin\StoreController::class);

        // Company details
        Route::get('/company', [Admin\CompanyController::class, 'edit'])->name('company.edit');
        Route::put('/company', [Admin\CompanyController::class, 'update'])->name('company.update');

        // Transaction numbering (superuser only)
        Route::get('/numberings', [Admin\NumberingController::class, 'index'])->name('numberings.index');
        Route::put('/numberings', [Admin\NumberingController::class, 'update'])->name('numberings.update');

        // SAP B1 connection test
        Route::post('/company/test-sap', [Admin\CompanyController::class, 'testSap'])->name('company.test-sap');

        // Activity log
        Route::get('/activity-logs', [Admin\ActivityLogController::class, 'index'])->name('logs.index');

        // Tipe pembayaran — global, bisa dipakai di beberapa gerai
        Route::resource('payment-types', Admin\PaymentTypeController::class)->except('show');

        // Mata uang — global; nominal pecahan dikelola per mata uang (show = halaman pecahan)
        Route::resource('currencies', Admin\CurrencyController::class)
            ->parameters(['currencies' => 'currency']);
        Route::post('currencies/{currency}/denominations', [Admin\CurrencyController::class, 'storeDenomination'])->name('currencies.denominations.store');
        Route::delete('currencies/{currency}/denominations/{denomination}', [Admin\CurrencyController::class, 'destroyDenomination'])->name('currencies.denominations.destroy');

        // Placeholder untuk fitur yang belum dibangun
        $placeholders = [
            'bundles' => 'Produk Paket',
            'customer-groups' => 'Grup Pelanggan',
            'supplier-groups' => 'Grup Pemasok',
            'customers' => 'Daftar Pelanggan',
            'suppliers' => 'Daftar Pemasok',
        ];

        foreach ($placeholders as $page => $title) {
            Route::get('/'.$page, fn (string $page) => app(Admin\PlaceholderController::class)->show($page))
                ->defaults('page', $page)
                ->name($page.'.index');
        }

        // Password reset (must come from the edit user page)
        Route::post('/users/{user}/reset-password', [Admin\UserController::class, 'resetPassword'])
            ->name('users.reset-password');

        // Users
        Route::resource('users', Admin\UserController::class)->except('show');

        // Product catalog
        Route::resource('groups', Admin\ProductGroupController::class)->except('show')->names('groups');
        Route::resource('brands', Admin\BrandController::class)->except('show')->names('brands');
        Route::resource('products', Admin\ProductController::class)->except('show');
        Route::resource('price-lists', Admin\PriceListController::class)
            ->parameters(['price-lists' => 'priceList']);
        Route::post('price-lists/{priceList}/items', [Admin\PriceListController::class, 'storeItem'])->name('price-lists.items.store');
        Route::delete('price-lists/{priceList}/items/{item}', [Admin\PriceListController::class, 'destroyItem'])->name('price-lists.items.destroy');
        Route::resource('promotions', Admin\PromotionController::class)->except('show');
    });
