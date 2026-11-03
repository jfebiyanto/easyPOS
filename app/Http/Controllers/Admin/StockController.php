<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Support\StockService;
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

        return view('admin.stock.index', [
            'storeId' => $storeId,
            'store' => $storeId ? Store::find($storeId) : null,
            'products' => $products,
            'balances' => $storeId ? StockService::balances($storeId, $products->pluck('id')->all()) : [],
            'stores' => $request->user()->isOwner()
                ? Store::where('company_id', current_company_id())->orderBy('code')->get()
                : collect(),
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
