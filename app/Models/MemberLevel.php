<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class MemberLevel extends Model
{
    use HasUuid7;

    protected $fillable = [
        'company_id', 'name', 'min_points', 'earn_amount_per_point', 'order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_points' => 'decimal:2',
            'earn_amount_per_point' => 'decimal:2',
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
