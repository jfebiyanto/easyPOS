<?php

namespace App\Models;

class Product extends MasterModel
{
    protected $fillable = [
        'company_id', 'product_group_id', 'brand_id', 'unit_id', 'sku', 'barcode', 'name',
        'description', 'base_price', 'cost_price', 'track_stock', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'base_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
        ];
    }

    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function priceListItems()
    {
        return $this->hasMany(PriceListItem::class);
    }
}
