<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class LoyaltyConfig extends Model
{
    use HasUuid7;

    protected $table = 'loyalty_config';

    protected $fillable = ['company_id', 'min_spend', 'rounding', 'expiry_days', 'is_active'];

    protected function casts(): array
    {
        return [
            'min_spend' => 'decimal:2',
            'expiry_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public static function current(?string $companyId = null): self
    {
        $companyId = $companyId ?? current_company_id();

        return static::firstOrCreate(
            ['company_id' => $companyId],
            ['min_spend' => 0, 'rounding' => 'down', 'expiry_days' => null, 'is_active' => true]
        );
    }
}
