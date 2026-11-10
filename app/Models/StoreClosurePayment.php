<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class StoreClosurePayment extends Model
{
    use HasUuid7;

    protected $fillable = ['store_closure_id', 'payment_type_id', 'payment_code', 'kind', 'expected_amount', 'confirmed_at'];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }
}
