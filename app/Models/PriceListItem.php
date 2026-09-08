<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    use \App\Traits\HasUuid7;

    protected $fillable = ['price_list_id', 'product_id', 'price', 'min_qty'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}