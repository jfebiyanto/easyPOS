<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentType extends MasterModel
{
    protected $fillable = ['company_id', 'code', 'kind', 'description', 'order_number', 'count_type', 'credit_card_id', 'is_qr_payment', 'qr_image_path', 'is_offline_allowed', 'is_active'];

    /** Kode tipe pembayaran yang dikenali posting Incoming Payment SAP B1. */
    public const CODE_CASH = 'CASH';

    public const CODE_QRIS = 'QRIS';

    public const CODE_EDC = 'EDC';

    /** Jenis tipe pembayaran: cash tidak perlu dipetakan ke Credit Card SAP B1; custom wajib (integrasi SAP aktif). */
    public const KIND_CASH = 'cash';

    public const KIND_CUSTOM = 'custom';

    public const KINDS = [
        self::KIND_CASH => 'Cash',
        self::KIND_CUSTOM => 'Custom',
    ];

    public const COUNT_TYPES = [
        'summary' => 'Summary',
        'detail' => 'Detail',
    ];

    protected static function booted(): void
    {
        // Tanpa jenis eksplisit: kode CASH = cash, selain itu custom.
        static::creating(function (self $paymentType) {
            $paymentType->kind ??= strtoupper((string) $paymentType->code) === self::CODE_CASH ? self::KIND_CASH : self::KIND_CUSTOM;
        });
    }

    /**
     * URL gambar QR statis, memakai host request agar satu origin dengan halaman yang menampilkannya.
     */
    public function getQrImageUrlAttribute(): ?string
    {
        return $this->is_qr_payment && $this->qr_image_path ? url('storage/'.$this->qr_image_path) : null;
    }

    public function isCash(): bool
    {
        return $this->kind === self::KIND_CASH;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_offline_allowed' => 'boolean',
            'is_qr_payment' => 'boolean',
            'order_number' => 'integer',
        ];
    }

    /**
     * Kartu kredit SAP B1 yang dipakai bila tipe ini diposting sebagai pembayaran non-tunai.
     *
     * @return BelongsTo<CreditCard, $this>
     */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * Gerai-gerai tempat tipe pembayaran ini dapat digunakan.
     */
    public function stores()
    {
        return $this->belongsToMany(Store::class);
    }
}
