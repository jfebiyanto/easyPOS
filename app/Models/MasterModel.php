<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class MasterModel extends Model
{
    use HasUuid7;

    public function scopeForStore(Builder $query, ?string $storeId = null): Builder
    {
        return $query->where('store_id', $storeId ?? session('active_store_id'));
    }
}