<?php

namespace App\Models;

/**
 * Keranjang POS yang sedang berjalan milik satu kasir (disimpan otomatis dan dipulihkan setelah refresh).
 */
class PosActiveCart extends MasterModel
{
    /** Keranjang lebih tua dari ini tidak dipulihkan; dibuang dan dicatat sebagai kedaluwarsa. */
    public const EXPIRES_AFTER_HOURS = 24;

    protected $fillable = ['company_id', 'store_id', 'cashier_id', 'payload', 'item_count', 'gross_total'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'item_count' => 'integer',
            'gross_total' => 'decimal:2',
        ];
    }

    public function isExpired(): bool
    {
        return $this->updated_at->lt(now()->subHours(self::EXPIRES_AFTER_HOURS));
    }
}
