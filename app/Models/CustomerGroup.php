<?php

namespace App\Models;

class CustomerGroup extends MasterModel
{
    public const SOURCE_SAP = 'sap';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = ['company_id', 'code', 'name', 'source', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * Grup default aktif perusahaan, nilai awal untuk customer baru.
     */
    public static function defaultFor(string $companyId): ?self
    {
        return self::where('company_id', $companyId)->where('is_default', true)->where('is_active', true)->first();
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
