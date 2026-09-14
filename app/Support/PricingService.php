<?php

namespace App\Support;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;

/**
 * Resolusi harga jual untuk POS.
 * Prioritas (dievaluasi terendah→tertinggi, match terakhir menang):
 * default → tanggal efektif. Fallback: base_price.
 */
class PricingService
{
    public static function resolve(string $storeId, Product $product, float $qty = 1): float
    {
        $listIds = \Illuminate\Support\Facades\DB::table('store_price_lists')
            ->where('store_id', $storeId)
            ->pluck('price_list_id')
            ->all();

        if ($listIds === []) {
            return (float) $product->base_price;
        }

        $today = now()->toDateString();

        $lists = PriceList::query()
            ->whereIn('id', $listIds)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today))
            ->get();

        // default dulu, lalu dated → dated menang bila keduanya cocok.
        $chosen = $lists->sortBy(fn ($l) => $l->is_default ? 0 : 1)->last();

        if (! $chosen) {
            return (float) $product->base_price;
        }

        $item = PriceListItem::query()
            ->where('price_list_id', $chosen->id)
            ->where('product_id', $product->id)
            ->where('min_qty', '<=', max(1, $qty))
            ->orderByDesc('min_qty')
            ->first();

        return $item ? (float) $item->price : (float) $product->base_price;
    }
}
