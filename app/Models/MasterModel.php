<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class MasterModel extends Model
{
    use HasUuid7;

    public function scopeForCompany(Builder $query, ?string $companyId = null): Builder
    {
        return $query->where('company_id', $companyId ?? current_company_id());
    }

    public function scopeForStore(Builder $query, ?string $storeId = null): Builder
    {
        return $query->where('store_id', $storeId ?? active_store_id());
    }
}
