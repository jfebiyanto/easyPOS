<?php

namespace App\Models;

class CustomerGroup extends MasterModel
{
    protected $fillable = ['company_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
