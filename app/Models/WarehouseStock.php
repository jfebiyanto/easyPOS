<?php

namespace App\Models;

/**
 * Saldo inventory per produk per warehouse (padanan OITW di SAP B1).
 */
class WarehouseStock extends MasterModel
{
    protected $fillable = ['company_id', 'warehouse_id', 'product_id', 'on_hand', 'committed', 'on_order', 'avg_price'];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:3',
            'committed' => 'decimal:3',
            'on_order' => 'decimal:3',
            'avg_price' => 'decimal:2',
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
