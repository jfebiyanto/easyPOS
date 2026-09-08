<?php

namespace App\Models;

class Promotion extends MasterModel
{
    protected $fillable = [
        'store_id', 'code', 'name', 'type', 'value', 'product_id', 'min_qty',
        'starts_at', 'ends_at', 'is_active', 'description',
    ];

    public const TYPES = [
        'discount_percent' => 'Diskon Persen (%)',
        'discount_amount' => 'Diskon Nominal (Rp)',
        'special_price' => 'Harga Khusus',
        'buy_x_get_y' => 'Beli X Gratis Y',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}