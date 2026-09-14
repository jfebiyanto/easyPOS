<?php

namespace App\Support;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;

/**
 * Sinkronisasi harga produk ke pricelist default.
 * Pricelist default = company-level default + default yang di-assign ke store.
 */
class PriceListSync
{
    public static function syncBasePrice(Product $product): void
    {
        foreach (self::defaultPriceListIds($product->company_id) as $priceListId) {
            PriceListItem::updateOrCreate(
                ['price_list_id' => $priceListId, 'product_id' => $product->id, 'min_qty' => 1],
                ['price' => $product->base_price]
            );
        }
    }

    public static function defaultPriceListIds(string $companyId): array
    {
        $companyDefaults = PriceList::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->pluck('id');

        $storeDefaults = PriceList::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereHas('stores', fn ($q) => $q->where('store_price_lists.is_default', true))
            ->pluck('id');

        return $companyDefaults->merge($storeDefaults)->unique()->values()->all();
    }
}
