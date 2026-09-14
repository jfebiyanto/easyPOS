<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * Konfigurasi penomoran transaksi — satu baris per tipe transaksi.
 */
class NumberingConfig extends Model
{
    use HasUuid7;

    public const TYPES = [
        'sales' => 'Sales',
        'sales_return' => 'Return',
        'stock_adjustment' => 'Stock Adjustment',
        'stock_request' => 'Stock Request',
        'stock_transfer_shipment' => 'Stock Transfer Shipment',
        'stock_transfer_receipt' => 'Stock Transfer Receipt',
        'goods_receipt' => 'Goods Receipt',
        'goods_issue' => 'Goods Issue',
        'stock_counting' => 'Stock Counting',
        'purchase_order' => 'Purchase Order',
        'purchase_receipt' => 'Purchase Receipt',
        'purchase_return' => 'Purchase Return',
    ];

    public const RESET_PERIODS = [
        'daily' => 'Harian',
        'monthly' => 'Bulanan',
        'yearly' => 'Tahunan',
        'never' => 'Tidak pernah',
    ];

    protected $fillable = [
        'company_id', 'transaction_type', 'prefix', 'padding', 'separator', 'reset_period', 'last_period', 'last_number',
    ];

    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'last_number' => 'integer',
        ];
    }

    /**
     * Ambil konfigurasi semua tipe transaksi, dibuat otomatis bila belum ada.
     */
    public static function allTypes(?string $companyId = null): \Illuminate\Support\Collection
    {
        $companyId = $companyId ?? current_company_id();

        $existing = static::query()->where('company_id', $companyId)->pluck('transaction_type')->flip();

        foreach (array_keys(self::TYPES) as $type) {
            if (! $existing->has($type)) {
                static::create([
                    'company_id' => $companyId,
                    'transaction_type' => $type,
                    'prefix' => strtoupper(substr($type, 0, 3)),
                    'padding' => 4,
                    'separator' => '/',
                    'reset_period' => 'monthly',
                    'last_number' => 0,
                ]);
            }
        }

        $configs = static::query()->where('company_id', $companyId)->get()->keyBy('transaction_type');

        return collect(array_keys(self::TYPES))
            ->map(fn ($type) => $configs->get($type))
            ->filter();
    }

    public function periodPart($now = null): string
    {
        $now = $now ? \Illuminate\Support\Carbon::parse($now) : now();

        return match ($this->reset_period) {
            'daily' => $now->format('Ymd'),
            'monthly' => $now->format('Ym'),
            'yearly' => $now->format('Y'),
            default => '',
        };
    }

    public function formatNumber(?int $number = null, $now = null): string
    {
        $number = (int) ($number ?? 0);
        if ($number < 1) {
            $number = 1;
        }

        $parts = [$this->prefix ?: strtoupper(substr($this->transaction_type, 0, 3))];
        if ($period = $this->periodPart($now)) {
            $parts[] = $period;
        }
        $parts[] = str_pad((string) $number, max(1, (int) ($this->padding ?? 4)), '0', STR_PAD_LEFT);

        return implode($this->separator ?: '/', $parts);
    }

    /**
     * Ambil nomor berikutnya untuk tipe transaksi.
     * Counter otomatis ter-reset ketika periode berganti (harian/bulanan/tahunan).
     */
    public static function nextNumber(string $transactionType, $now = null, ?string $companyId = null): string
    {
        $companyId = $companyId ?? current_company_id();

        $config = static::query()
            ->where('company_id', $companyId)
            ->where('transaction_type', $transactionType)
            ->first();

        if (! $config) {
            if (! array_key_exists($transactionType, self::TYPES)) {
                abort(404, 'Tipe transaksi tidak dikenal.');
            }

            $config = static::create([
                'company_id' => $companyId,
                'transaction_type' => $transactionType,
                'prefix' => strtoupper(substr($transactionType, 0, 3)),
                'padding' => 4,
                'separator' => '/',
                'reset_period' => 'monthly',
                'last_number' => 0,
            ]);
        }

        $now = $now ? \Illuminate\Support\Carbon::parse($now) : now();
        $period = $config->periodPart($now);

        // Null-safe: nilai kosong diperlakukan sebagai 0, sehingga
        // nomor berikutnya langsung dimulai dari 1.
        $lastNumber = (int) ($config->last_number ?? 0);
        $lastPeriod = $config->last_period;

        // Reset hanya saat periode berganti (last_period sudah pernah terisi),
        // bukan pada penggunaan pertama setelah deployment/konfigurasi.
        $isNewPeriod = $config->reset_period !== 'never'
            && $lastPeriod !== null
            && $lastPeriod !== $period;

        $config->forceFill([
            'last_number' => $isNewPeriod ? 1 : $lastNumber + 1,
            'last_period' => $period,
        ])->save();

        return $config->formatNumber($config->last_number, $now);
    }
}