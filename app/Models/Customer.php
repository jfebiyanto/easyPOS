<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasUuid7;

    protected $fillable = [
        'company_id', 'code', 'name', 'phone', 'email', 'address', 'is_member',
        'member_level_id', 'points_balance', 'joined_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_member' => 'boolean',
            'is_active' => 'boolean',
            'points_balance' => 'decimal:2',
            'joined_at' => 'datetime',
        ];
    }

    public function memberLevel()
    {
        return $this->belongsTo(MemberLevel::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function loyaltyLedger()
    {
        return $this->hasMany(LoyaltyLedger::class);
    }
}
