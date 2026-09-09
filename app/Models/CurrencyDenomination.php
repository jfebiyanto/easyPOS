<?php

namespace App\Models;

class CurrencyDenomination extends MasterModel
{
    protected $fillable = ['currency_id', 'value', 'is_active'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
