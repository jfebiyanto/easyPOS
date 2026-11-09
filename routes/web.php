<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\PosSessionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.attempt');
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Modul POS (React) — sesi web + CSRF.
Route::middleware('auth')->prefix('pos')->name('pos.')->group(function () {
    Route::get('/', [PosController::class, 'shell'])->name('index');
    // Mengambil alih sesi POS tidak melewati pemeriksaan sesi aktif (justru dipakai saat sesi sedang di perangkat lain).
    Route::post('/session/takeover', [PosSessionController::class, 'takeover'])->middleware('pos.store')->name('session.takeover');
    Route::middleware(['pos.store', 'pos.session'])->group(function () {
        Route::post('/session/heartbeat', [PosSessionController::class, 'heartbeat'])->name('session.heartbeat');
        Route::get('/bootstrap', [PosController::class, 'bootstrap'])->name('bootstrap');
        Route::post('/session/acknowledge', [PosController::class, 'acknowledgeSession'])->name('session.acknowledge');
        Route::get('/products', [PosController::class, 'products'])->name('products');
        Route::get('/promotions', [PosController::class, 'promotions'])->name('promotions');
        Route::get('/sales-lookup', [PosController::class, 'lookupSale'])->name('sales.lookup');
        Route::get('/sales', [PosController::class, 'todaySales'])->name('sales.index');
        Route::post('/sales', [PosController::class, 'storeSale'])->name('sales.store');
        Route::get('/sales/{sale}', [PosController::class, 'showSale'])->name('sales.show');
        Route::post('/sales/{sale}/void', [PosController::class, 'voidSale'])->name('sales.void');
        Route::post('/returns', [PosController::class, 'storeReturn'])->name('returns.store');
        Route::get('/customers', [PosController::class, 'customers'])->name('customers.index');
        Route::post('/customers', [PosController::class, 'storeCustomer'])->name('customers.store');
        Route::put('/cart', [PosController::class, 'saveCart'])->name('cart.save');
        Route::post('/cart/restore', [PosController::class, 'restoreCart'])->name('cart.restore');
        Route::post('/cart/events', [PosController::class, 'cartEvent'])->name('cart.events');
        Route::get('/holds', [PosController::class, 'holds'])->name('holds.index');
        Route::post('/holds', [PosController::class, 'storeHold'])->name('holds.store');
        Route::delete('/holds/{draft}', [PosController::class, 'destroyHold'])->name('holds.destroy');
        Route::post('/holds/{draft}/consume', [PosController::class, 'consumeHold'])->name('holds.consume');
        Route::post('/holds/{draft}/release', [PosController::class, 'releaseHold'])->name('holds.release');
    });
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'admin', 'backoffice.access'])
    ->group(function () {
        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/today', [Admin\DashboardController::class, 'today'])->name('dashboard.today');
        Route::get('/dashboard/overview', [Admin\DashboardController::class, 'overview'])->name('dashboard.overview');

        // Store management (HO can manage multiple stores)
        Route::post('/stores/switch/{store}', [Admin\StoreController::class, 'switch'])->name('stores.switch');
        Route::resource('stores', Admin\StoreController::class);

        // Company details
        Route::get('/company', [Admin\CompanyController::class, 'edit'])->name('company.edit');
        Route::put('/company', [Admin\CompanyController::class, 'update'])->name('company.update');

        // Transaction numbering (superuser only)
        Route::get('/numberings', [Admin\NumberingController::class, 'index'])->name('numberings.index');
        Route::put('/numberings', [Admin\NumberingController::class, 'update'])->name('numberings.update');

        // SAP B1: simpan konfigurasi (form terpisah) + test koneksi
        Route::put('/company/sap', [Admin\CompanyController::class, 'updateSap'])->name('company.update-sap');
        Route::post('/company/test-sap', [Admin\CompanyController::class, 'testSap'])->name('company.test-sap');

        // Impor data SAP B1 + interval sinkronisasi (muncul bila integrasi SAP aktif)
        Route::get('/sap-import', [Admin\SapImportController::class, 'index'])->name('sap-import.index');
        Route::post('/sap-import/run', [Admin\SapImportController::class, 'run'])->name('sap-import.run');
        Route::post('/sap-import/cancel', [Admin\SapImportController::class, 'cancel'])->name('sap-import.cancel');
        Route::put('/sap-import/settings', [Admin\SapImportController::class, 'updateSettings'])->name('sap-import.settings');
        Route::get('/sap-import/status', [Admin\SapImportController::class, 'status'])->name('sap-import.status');

        // Posting penjualan POS ke SAP B1
        Route::get('/sap-postings', [Admin\SapPostingController::class, 'index'])->name('sap-postings.index');
        Route::post('/sap-postings/{sale}/retry', [Admin\SapPostingController::class, 'retry'])->name('sap-postings.retry');
        Route::get('/sap-return-postings', [Admin\SapPostingController::class, 'returns'])->name('sap-postings.returns');
        Route::post('/sap-return-postings/{salesReturn}/retry', [Admin\SapPostingController::class, 'retryReturn'])->name('sap-postings.returns.retry');

        // Activity log
        Route::get('/activity-logs', [Admin\ActivityLogController::class, 'index'])->name('logs.index');
        Route::get('/pos-sessions', [Admin\PosSessionController::class, 'index'])->name('pos-sessions.index');
        Route::get('/cancellations', [Admin\CancellationReportController::class, 'index'])->name('cancellations.index');
        Route::get('/cancellations/export', [Admin\CancellationReportController::class, 'export'])->name('cancellations.export');

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
            'supplier-groups' => 'Grup Pemasok',
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

        // Customer & loyalty
        Route::resource('customers', Admin\CustomerController::class);
        Route::post('customers/{customer}/adjust-points', [Admin\CustomerController::class, 'adjustPoints'])->name('customers.adjust-points');
        Route::post('customers/{customer}/sap-sync', [Admin\CustomerController::class, 'syncSap'])->name('customers.sap-sync');
        Route::get('member-levels', [Admin\MemberLevelController::class, 'index'])->name('member-levels.index');
        Route::get('member-levels/create', [Admin\MemberLevelController::class, 'create'])->name('member-levels.create');
        Route::post('member-levels', [Admin\MemberLevelController::class, 'store'])->name('member-levels.store');
        Route::put('member-levels/config', [Admin\MemberLevelController::class, 'updateConfig'])->name('member-levels.config');
        Route::get('member-levels/{memberLevel}/edit', [Admin\MemberLevelController::class, 'edit'])->name('member-levels.edit');
        Route::put('member-levels/{memberLevel}', [Admin\MemberLevelController::class, 'update'])->name('member-levels.update');
        Route::delete('member-levels/{memberLevel}', [Admin\MemberLevelController::class, 'destroy'])->name('member-levels.destroy');

        // Tutup toko (EOD) + review stok
        Route::get('/eod', [Admin\StoreClosureController::class, 'index'])->name('eod.index');
        Route::post('/eod', [Admin\StoreClosureController::class, 'store'])->name('eod.store');
        Route::post('/eod/reopen', [Admin\StoreClosureController::class, 'reopen'])->name('eod.reopen');
        Route::get('/stock-review', [Admin\StockReviewController::class, 'index'])->name('stock-review.index');
        Route::post('/stock-review/{sale}/resolve', [Admin\StockReviewController::class, 'resolve'])->name('stock-review.resolve');

        // Pengiriman
        Route::get('/deliveries', [Admin\DeliveryController::class, 'index'])->name('deliveries.index');
        Route::put('/deliveries/{delivery}', [Admin\DeliveryController::class, 'update'])->name('deliveries.update');

        // Laporan
        Route::get('/reports', [Admin\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [Admin\ReportController::class, 'export'])->name('reports.export');
        Route::get('/reports/{report}', [Admin\ReportController::class, 'show'])->name('reports.show');

        // Stock: saldo, ledger, adjustment, transfer
        Route::get('/stock', [Admin\StockController::class, 'index'])->name('stock.index');
        Route::get('/stock/{product}/ledger', [Admin\StockController::class, 'ledger'])->name('stock.ledger');
        Route::get('/stock/{product}/mutations', [Admin\StockController::class, 'mutations'])->name('stock.mutations');

        Route::get('/stock-adjustments', [Admin\StockAdjustmentController::class, 'index'])->name('stock-adjustments.index');
        Route::get('/stock-adjustments/create', [Admin\StockAdjustmentController::class, 'create'])->name('stock-adjustments.create');
        Route::post('/stock-adjustments', [Admin\StockAdjustmentController::class, 'store'])->name('stock-adjustments.store');
        Route::post('/stock-adjustments/{stockAdjustment}/post', [Admin\StockAdjustmentController::class, 'post'])->name('stock-adjustments.post');

        Route::get('/stock-transfers', [Admin\StockTransferController::class, 'index'])->name('stock-transfers.index');

        Route::get('/stock-transfer-shipments', [Admin\StockTransferShipmentController::class, 'index'])->name('stock-transfer-shipments.index');
        Route::get('/stock-transfer-shipments/create', [Admin\StockTransferShipmentController::class, 'create'])->name('stock-transfer-shipments.create');
        Route::post('/stock-transfer-shipments', [Admin\StockTransferShipmentController::class, 'store'])->name('stock-transfer-shipments.store');
        Route::get('/stock-transfer-shipments/{shipment}', [Admin\StockTransferShipmentController::class, 'show'])->name('stock-transfer-shipments.show');
        Route::get('/stock-transfer-shipments/{shipment}/edit', [Admin\StockTransferShipmentController::class, 'edit'])->name('stock-transfer-shipments.edit');
        Route::put('/stock-transfer-shipments/{shipment}', [Admin\StockTransferShipmentController::class, 'update'])->name('stock-transfer-shipments.update');
        Route::delete('/stock-transfer-shipments/{shipment}', [Admin\StockTransferShipmentController::class, 'destroy'])->name('stock-transfer-shipments.destroy');
        Route::post('/stock-transfer-shipments/{shipment}/post', [Admin\StockTransferShipmentController::class, 'post'])->name('stock-transfer-shipments.post');
        Route::post('/stock-transfer-shipments/{shipment}/retry', [Admin\StockTransferShipmentController::class, 'retry'])->name('stock-transfer-shipments.retry');

        Route::get('/stock-transfer-receipts', [Admin\StockTransferReceiptController::class, 'index'])->name('stock-transfer-receipts.index');
        Route::get('/stock-transfer-receipts/create', [Admin\StockTransferReceiptController::class, 'create'])->name('stock-transfer-receipts.create');
        Route::post('/stock-transfer-receipts', [Admin\StockTransferReceiptController::class, 'store'])->name('stock-transfer-receipts.store');
        Route::get('/stock-transfer-receipts/{receipt}', [Admin\StockTransferReceiptController::class, 'show'])->name('stock-transfer-receipts.show');
        Route::post('/stock-transfer-receipts/{receipt}/retry', [Admin\StockTransferReceiptController::class, 'retry'])->name('stock-transfer-receipts.retry');
        Route::get('/stock-transfer-returns', [Admin\StockTransferReturnController::class, 'index'])->name('stock-transfer-returns.index');
        Route::get('/stock-transfer-returns/create', [Admin\StockTransferReturnController::class, 'create'])->name('stock-transfer-returns.create');
        Route::post('/stock-transfer-returns', [Admin\StockTransferReturnController::class, 'store'])->name('stock-transfer-returns.store');
        Route::get('/stock-transfer-returns/{stockReturn}', [Admin\StockTransferReturnController::class, 'show'])->name('stock-transfer-returns.show');
        Route::post('/stock-transfer-returns/{stockReturn}/retry', [Admin\StockTransferReturnController::class, 'retry'])->name('stock-transfer-returns.retry');

        // Product catalog
        Route::resource('warehouses', Admin\WarehouseController::class)->except('show');
        Route::resource('customer-groups', Admin\CustomerGroupController::class)->except('show')
            ->parameters(['customer-groups' => 'customerGroup']);
        Route::resource('tax-groups', Admin\TaxGroupController::class)->except('show')
            ->parameters(['tax-groups' => 'taxGroup']);
        Route::resource('units', Admin\UnitController::class)->except('show');
        Route::resource('groups', Admin\ProductGroupController::class)->except('show')->names('groups');
        Route::resource('brands', Admin\BrandController::class)->except('show')->names('brands');
        Route::resource('products', Admin\ProductController::class)->except('show');
        Route::resource('price-lists', Admin\PriceListController::class)
            ->parameters(['price-lists' => 'priceList']);
        Route::post('price-lists/{priceList}/items', [Admin\PriceListController::class, 'storeItem'])->name('price-lists.items.store');
        Route::delete('price-lists/{priceList}/items/{item}', [Admin\PriceListController::class, 'destroyItem'])->name('price-lists.items.destroy');
        Route::get('promotion-hierarchy', [Admin\PromotionHierarchyController::class, 'edit'])->name('promotion-hierarchy.edit');
        Route::put('promotion-hierarchy', [Admin\PromotionHierarchyController::class, 'update'])->name('promotion-hierarchy.update');
        Route::resource('promotions', Admin\PromotionController::class)->except('show');
    });
