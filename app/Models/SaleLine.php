<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class SaleLine extends Model
{
    use HasUuid7;

    protected $fillable = [
        'sale_id', 'product_id', 'sku_snapshot', 'name_snapshot', 'unit_price', 'qty',
        'discount', 'promotion_id', 'promotion_name_snapshot', 'promotion_discount',
        'tax_amount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'qty' => 'decimal:3',
            'discount' => 'decimal:2',
            'promotion_discount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }
}
