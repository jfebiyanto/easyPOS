<?php

namespace App\Models;

/**
 * Saldo inventory per produk per warehouse (padanan OITW di SAP B1).
 */
class WarehouseStock extends MasterModel
{
    protected $fillable = ['company_id', 'warehouse_id', 'product_id', 'on_hand', 'committed', 'on_order', 'sap_on_hand', 'sap_checked_at'];

    /**
     * Cost item hanya dilihat di SAP B1.
     */
    protected $hidden = ['avg_price'];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:3',
            'committed' => 'decimal:3',
            'on_order' => 'decimal:3',
            'avg_price' => 'decimal:2',
            'sap_on_hand' => 'decimal:3',
            'sap_checked_at' => 'datetime',
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
