<?php

namespace App\Support;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\StockLedger;
use Illuminate\Support\Collection;

/**
 * Pusat pencatatan dan pembacaan stok.
 * Semua perubahan stok WAJIB lewat record() agar ledger tetap append-only.
 */
class StockService
{
    public static function balance(string $storeId, string $productId): float
    {
        return (float) StockLedger::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->sum('qty');
    }

    /**
     * @param  array<int,string>  $productIds
     * @return array<string,float> product_id => qty
     */
    public static function balances(string $storeId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return StockLedger::query()
            ->where('store_id', $storeId)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, sum(qty) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    public static function record(
        string $storeId,
        string $productId,
        string $type,
        float $qty,
        array $options = []
    ): StockLedger {
        return StockLedger::create([
            'company_id' => $options['company_id'] ?? current_company_id(),
            'store_id' => $storeId,
            'product_id' => $productId,
            'type' => $type,
            'qty' => $qty,
            'unit_cost' => $options['unit_cost'] ?? null,
            'ref_type' => $options['ref_type'] ?? null,
            'ref_id' => $options['ref_id'] ?? null,
            'note' => $options['note'] ?? null,
            'created_by' => $options['created_by'] ?? auth()->id(),
        ]);
    }

    public static function allowsNegative(): bool
    {
        return (bool) CompanyDetail::query()->value('allow_negative_stock');
    }

    /**
     * Pastikan saldo setelah delta tidak negatif (kecuali diizinkan company).
     */
    public static function assertNonNegative(string $storeId, string $productId, float $delta): void
    {
        if (self::allowsNegative()) {
            return;
        }

        $after = self::balance($storeId, $productId) + $delta;

        if ($after < 0) {
            $sku = Product::whereKey($productId)->value('sku') ?? $productId;
            abort(422, "Stok tidak mencukupi untuk produk {$sku} (saldo akan menjadi {$after}).");
        }
    }

    /**
     * Saldo seluruh produk pada satu store.
     *
     * @return Collection<int,\App\Models\StockLedger>
     */
    public static function ledgerFor(string $storeId, string $productId): Collection
    {
        return StockLedger::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->with('actor')
            ->orderByDesc('created_at')
            ->get();
    }
}
