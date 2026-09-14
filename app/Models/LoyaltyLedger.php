<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class LoyaltyLedger extends Model
{
    use HasUuid7;

    public const UPDATED_AT = null;

    protected $table = 'loyalty_ledger';

    protected $fillable = [
        'company_id', 'customer_id', 'type', 'points', 'balance_after',
        'ref_type', 'ref_id', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
