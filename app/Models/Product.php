<?php

namespace App\Models;

class Product extends MasterModel
{
    protected $fillable = [
        'store_id', 'product_group_id', 'brand_id', 'sku', 'barcode', 'name',
        'description', 'unit', 'base_price', 'cost_price', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function priceListItems()
    {
        return $this->hasMany(PriceListItem::class);
    }
}