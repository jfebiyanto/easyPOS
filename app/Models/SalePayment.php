<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    use HasUuid7;

    protected $fillable = [
        'sale_id', 'payment_type_id', 'amount', 'status', 'provider', 'provider_ref', 'approval_code', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class);
    }
}
