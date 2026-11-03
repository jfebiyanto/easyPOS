<?php

namespace App\Support;

use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Saldo dan pergerakan stok per warehouse. Saat ini baru kerangka: belum dipakai oleh
 * penjualan, retur, transfer, atau penyesuaian. Impor SAP mengisi saldo langsung ke
 * warehouse_stocks tanpa melewati buku besar ini.
 */
class WarehouseStockService
{
    /**
     * @param  array<int,string>  $productIds
     * @return array<string, WarehouseStock> product_id => saldo
     */
    public static function balances(?string $warehouseId, array $productIds): array
    {
        if ($warehouseId === null || $productIds === []) {
            return [];
        }

        return WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id')
            ->all();
    }

    /**
     * Catat satu pergerakan: tambah baris buku besar dan perbarui saldo on_hand secara atomik.
     *
     * @param  array{company_id?: string, unit_cost?: float, ref_type?: string, ref_id?: string, note?: string, created_by?: string}  $options
     */
    public static function record(
        Warehouse $warehouse,
        string $productId,
        string $type,
        float $qty,
        array $options = []
    ): WarehouseStockLedger {
        return DB::transaction(function () use ($warehouse, $productId, $type, $qty, $options) {
            $stock = WarehouseStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first()
                ?? new WarehouseStock([
                    'company_id' => $warehouse->company_id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $productId,
                ]);

            $stock->on_hand = round((float) $stock->on_hand + $qty, 3);
            $stock->save();

            return WarehouseStockLedger::create([
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $productId,
                'type' => $type,
                'qty' => $qty,
                'balance_after' => $stock->on_hand,
                'unit_cost' => $options['unit_cost'] ?? null,
                'ref_type' => $options['ref_type'] ?? null,
                'ref_id' => $options['ref_id'] ?? null,
                'note' => $options['note'] ?? null,
                'created_by' => $options['created_by'] ?? auth()->id(),
            ]);
        });
    }
}
