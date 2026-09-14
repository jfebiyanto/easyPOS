<?php

namespace App\Models;

class PriceList extends MasterModel
{
    protected $fillable = [
        'company_id', 'store_id', 'code', 'name', 'is_default',
        'effective_from', 'effective_until', 'is_active',
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

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class, 'store_price_lists')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }
}
