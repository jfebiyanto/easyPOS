<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasUuid7;

    protected $fillable = ['code', 'name', 'address', 'pic_name', 'pic_phone', 'ppn_enabled', 'ppn_rate', 'currency'];

    protected function casts(): array
    {
        return [
            'ppn_enabled' => 'boolean',
            'ppn_rate' => 'decimal:2',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function productGroups()
    {
        return $this->hasMany(ProductGroup::class);
    }

    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    public function priceLists()
    {
        return $this->hasMany(PriceList::class);
    }

    /**
     * The price list used to auto-fill product prices (default + active).
     */
    public function defaultPriceList()
    {
        return $this->hasOne(PriceList::class)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('effective_from');
    }
}