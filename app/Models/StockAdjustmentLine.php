<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class StockAdjustmentLine extends Model
{
    use HasUuid7;

    protected $fillable = [
        'stock_adjustment_id', 'product_id', 'qty_before', 'qty_after', 'delta',
    ];

    protected function casts(): array
    {
        return [
            'qty_before' => 'decimal:3',
            'qty_after' => 'decimal:3',
            'delta' => 'decimal:3',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
