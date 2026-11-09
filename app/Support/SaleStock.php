<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Store;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;

/**
 * Stok untuk penjualan POS. Gerai yang terhubung ke warehouse memakai buku besar warehouse
 * (saldo tersinkron dengan SAP B1); gerai tanpa warehouse tetap memakai buku stok gerai lama.
 * Retur memakai buku yang sama dengan penjualan asalnya; penyesuaian stok mengikuti warehouse gerai dan diposting ke SAP.
 * Transfer (Shipment/Receipt) bukan lewat kelas ini: ia memakai buku besar warehouse langsung dan diposting ke SAP.
 */
class SaleStock
{
    public static function warehouseFor(string $storeId): ?Warehouse
    {
        $warehouseId = Store::whereKey($storeId)->value('warehouse_id');

        return $warehouseId ? Warehouse::find($warehouseId) : null;
    }

    public static function balance(string $storeId, string $productId): float
    {
        $warehouse = self::warehouseFor($storeId);

        if (! $warehouse) {
            return StockService::balance($storeId, $productId);
        }

        return (float) WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', $productId)->value('on_hand');
    }

    /**
     * @param  array<int, string>  $productIds
     * @return array<string, float> product_id => qty
     */
    public static function balances(string $storeId, array $productIds): array
    {
        $warehouse = self::warehouseFor($storeId);

        if (! $warehouse) {
            return StockService::balances($storeId, $productIds);
        }

        return collect(WarehouseStockService::balances($warehouse->id, $productIds))
            ->map(fn (WarehouseStock $stock) => (float) $stock->on_hand)
            ->all();
    }

    /**
     * Tolak bila saldo setelah delta negatif (kecuali perusahaan mengizinkan stok minus).
     */
    public static function assertAvailable(string $storeId, string $productId, float $delta): void
    {
        if (StockService::allowsNegative()) {
            return;
        }

        $after = self::balance($storeId, $productId) + $delta;

        if ($after < 0) {
            $sku = Product::whereKey($productId)->value('sku') ?? $productId;
            abort(422, "Stok tidak mencukupi untuk produk {$sku} (saldo akan menjadi {$after}).");
        }
    }

    /**
     * Catat pergerakan penjualan (qty bertanda) pada buku stok yang dipakai gerai.
     *
     * @param  array{company_id: string, ref_type: string, ref_id: string, note?: string, created_by?: ?string}  $options
     */
    public static function record(string $storeId, string $productId, string $type, float $qty, array $options): void
    {
        $warehouse = self::warehouseFor($storeId);

        if (! $warehouse) {
            StockService::record($storeId, $productId, $type, $qty, $options);

            return;
        }

        WarehouseStockService::record($warehouse, $productId, $type, $qty, [
            'ref_type' => $options['ref_type'],
            'ref_id' => $options['ref_id'],
            'note' => $options['note'] ?? null,
            'created_by' => $options['created_by'] ?? null,
        ]);
    }

    /**
     * Apakah penjualan ini mengurangi buku besar warehouse (bukan buku stok gerai lama).
     */
    public static function usesWarehouseLedger(string $saleId): bool
    {
        return WarehouseStockLedger::where('ref_type', 'sale')->where('ref_id', $saleId)->exists();
    }
}
