<?php

namespace App\Models;

class PaymentType extends MasterModel
{
    protected $fillable = ['company_id', 'code', 'description', 'order_number', 'count_type', 'is_offline_allowed', 'is_active'];

    public const COUNT_TYPES = [
        'summary' => 'Summary',
        'detail' => 'Detail',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_offline_allowed' => 'boolean',
            'order_number' => 'integer',
        ];
    }

    /**
     * Gerai-gerai tempat tipe pembayaran ini dapat digunakan.
     */
    public function stores()
    {
        return $this->belongsToMany(Store::class);
    }
}
