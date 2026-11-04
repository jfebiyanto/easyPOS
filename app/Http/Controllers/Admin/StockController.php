<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\StockService;
use App\Support\WarehouseStockService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController extends Controller
{
    use AuthorizesCompany, ScopesToStore;

    public function index(Request $request): View
    {
        $storeId = $this->resolveStoreId($request, $request->query('store'));

        $products = Product::where('company_id', current_company_id())
            ->where('track_stock', true)
            ->with('unit')
            ->orderBy('sku')
            ->get();

        $store = $storeId ? Store::with('warehouse')->find($storeId) : null;
        $warehouse = $store?->warehouse;

        return view('admin.stock.index', [
            'storeId' => $storeId,
            'store' => $store,
            'products' => $products,
            'warehouse' => $warehouse,
            'balances' => WarehouseStockService::balances($warehouse?->id, $products->pluck('id')->all()),
            'stores' => $request->user()->isOwner()
                ? Store::where('company_id', current_company_id())->orderBy('code')->get()
                : collect(),
        ]);
    }

    /**
     * Riwayat mutasi buku besar warehouse gerai (termasuk mutasi yang disalin dari SAP B1).
     */
    public function mutations(Request $request, Product $product): View
    {
        $this->ensureCompany($product->company_id);

        $storeId = $this->resolveStoreId($request, $request->query('store'));
        $store = $storeId ? Store::with('warehouse')->find($storeId) : null;
        $warehouse = $store?->warehouse;

        return view('admin.stock.mutations', [
            'product' => $product,
            'store' => $store,
            'storeId' => $storeId,
            'warehouse' => $warehouse,
            'stock' => $warehouse ? WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first() : null,
            'entries' => $warehouse
                ? WarehouseStockLedger::where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)
                    ->orderByDesc('sap_trans_seq')
                    ->orderByDesc('created_at')
                    ->paginate(20)
                    ->withQueryString()
                : null,
        ]);
    }

    public function ledger(Request $request, Product $product): View
    {
        $this->ensureCompany($product->company_id);

        $storeId = $this->resolveStoreId($request, $request->query('store'));

        return view('admin.stock.ledger', [
            'product' => $product,
            'store' => $storeId ? Store::find($storeId) : null,
            'storeId' => $storeId,
            'entries' => $storeId ? StockService::ledgerFor($storeId, $product->id) : collect(),
            'balance' => $storeId ? StockService::balance($storeId, $product->id) : 0,
        ]);
    }
}
