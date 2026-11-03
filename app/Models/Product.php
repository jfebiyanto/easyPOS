<?php

namespace App\Models;

class Product extends MasterModel
{
    protected $fillable = [
        'company_id', 'product_group_id', 'brand_id', 'unit_id', 'sku', 'barcode', 'name', 'image_path',
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

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        // Pakai host dari request (bukan APP_URL statis) agar origin-nya sama
        // dengan halaman yang menampilkannya — menghindari blokir CSP lintas origin.
        return url('storage/'.$this->image_path);
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
