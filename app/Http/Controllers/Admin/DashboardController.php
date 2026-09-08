<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $store = Store::find(active_store_id());

        return view('admin.dashboard', [
            'store' => $store,
            'storeCount' => Store::count(),
            'userCount' => User::when($store, fn ($q) => $q->where('store_id', $store->id))->count(),
            'productCount' => Product::when($store, fn ($q) => $q->where('store_id', $store->id))->count(),
            'groupCount' => ProductGroup::when($store, fn ($q) => $q->where('store_id', $store->id))->count(),
            'brandCount' => Brand::when($store, fn ($q) => $q->where('store_id', $store->id))->count(),
            'promoCount' => Promotion::where('is_active', true)
                ->when($store, fn ($q) => $q->where('store_id', $store->id))
                ->count(),
        ]);
    }
}