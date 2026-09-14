<?php

namespace App\Models;

class ProductGroup extends MasterModel
{
    protected $fillable = ['company_id', 'code', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'product_group_id');
    }
}
