<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Warehouse extends MasterModel
{
    use HasFactory;

    protected $fillable = ['company_id', 'code', 'name', 'profit_center', 'cash_account', 'qris_account', 'edc_account', 'transit_warehouse_code', 'is_retail', 'is_transit', 'is_active'];

    protected function casts(): array
    {
        return ['is_retail' => 'boolean', 'is_transit' => 'boolean', 'is_active' => 'boolean'];
    }

    public function store()
    {
        return $this->hasOne(Store::class);
    }

    /**
     * Gudang in-transit milik gudang ini (kode dari UDF U_SOL_WHTR), di perusahaan yang sama.
     */
    public function transitWarehouse(): ?Warehouse
    {
        if (blank($this->transit_warehouse_code)) {
            return null;
        }

        return static::where('company_id', $this->company_id)
            ->where('code', $this->transit_warehouse_code)
            ->where('is_transit', true)
            ->first();
    }
}
