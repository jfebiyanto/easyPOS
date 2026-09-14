<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    use HasUuid7;

    protected $fillable = [
        'sale_id', 'recipient_name', 'phone', 'address', 'shipping_cost', 'status', 'note',
        'shipped_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_cost' => 'decimal:2',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
