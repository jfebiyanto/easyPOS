<?php

namespace App\Models;

use Database\Factories\CreditCardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kartu kredit SAP B1 (OCRC): QRIS, EDC bank, dll. Hanya-baca, diisi oleh sinkronisasi SAP.
 */
class CreditCard extends MasterModel
{
    /** @use HasFactory<CreditCardFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'code', 'name', 'gl_account'];

    /**
     * @return HasMany<PaymentType, $this>
     */
    public function paymentTypes(): HasMany
    {
        return $this->hasMany(PaymentType::class);
    }

    /**
     * Label untuk dropdown, mis. "2 · EDC BANK (1100-2002-0003)".
     */
    public function label(): string
    {
        return $this->code.' · '.$this->name.($this->gl_account ? " ({$this->gl_account})" : '');
    }
}
