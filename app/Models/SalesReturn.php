<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use HasUuid7;

    protected $fillable = [
        'company_id', 'store_id', 'number', 'original_sale_id', 'reason', 'total',
        'refund_method', 'receipt_verified', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'receipt_verified' => 'boolean',
        ];
    }

    public function lines()
    {
        return $this->hasMany(SalesReturnLine::class);
    }

    public function originalSale()
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }
}
