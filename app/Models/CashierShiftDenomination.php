<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class CashierShiftDenomination extends Model
{
    use HasUuid7;

    protected $fillable = ['cashier_shift_id', 'denomination_value', 'count', 'subtotal'];

    protected function casts(): array
    {
        return [
            'denomination_value' => 'decimal:2',
            'count' => 'integer',
            'subtotal' => 'decimal:2',
        ];
    }
}
