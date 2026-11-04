<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Warehouse extends MasterModel
{
    use HasFactory;

    protected $fillable = ['company_id', 'code', 'name', 'profit_center', 'cash_account', 'is_retail', 'is_active'];

    protected function casts(): array
    {
        return ['is_retail' => 'boolean', 'is_active' => 'boolean'];
    }

    public function store()
    {
        return $this->hasOne(Store::class);
    }
}
