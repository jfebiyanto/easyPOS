<?php

namespace App\Models;

class Currency extends MasterModel
{
    protected $fillable = ['code', 'symbol', 'has_rounding', 'decimal_places', 'is_active'];

    protected function casts(): array
    {
        return [
            'has_rounding' => 'boolean',
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function denominations()
    {
        return $this->hasMany(CurrencyDenomination::class);
    }
}
