<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    use HasUuid7;

    protected $fillable = [
        'stock_transfer_id', 'product_id', 'qty_requested', 'qty_shipped', 'qty_received', 'variance_reason',
    ];

    protected function casts(): array
    {
        return [
            'qty_requested' => 'decimal:3',
            'qty_shipped' => 'decimal:3',
            'qty_received' => 'decimal:3',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
