<?php

namespace App\Models;

class CustomerGroup extends MasterModel
{
    public const SOURCE_SAP = 'sap';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = ['company_id', 'code', 'name', 'source', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function isFromSap(): bool
    {
        return $this->source === self::SOURCE_SAP;
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
