<?php

namespace App\Models;

class PriceList extends MasterModel
{
    protected $fillable = [
        'store_id', 'code', 'name', 'is_default', 'effective_from', 'effective_until', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }
}