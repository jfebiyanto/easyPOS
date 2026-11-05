<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class StoreClosure extends Model
{
    use HasUuid7;

    protected $fillable = [
        'company_id', 'store_id', 'business_date', 'opened_at', 'closed_at',
        'sales_total', 'cash_expected', 'cash_counted', 'cash_variance', 'closed_by', 'note',
        'reopened_at', 'reopened_by', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'sales_total' => 'decimal:2',
            'cash_expected' => 'decimal:2',
            'cash_counted' => 'decimal:2',
            'cash_variance' => 'decimal:2',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function reopenedBy()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function denominations()
    {
        return $this->hasMany(StoreClosureDenomination::class);
    }

    /**
     * Apakah EOD store untuk tanggal bisnis ini sudah ditutup (dan belum dibuka ulang).
     */
    public static function isClosed(string $storeId, string $date): bool
    {
        return static::query()
            ->where('store_id', $storeId)
            ->whereDate('business_date', $date)
            ->whereNotNull('closed_at')
            ->exists();
    }

    /**
     * Tanggal posting transaksi: tanggal bisnis tertua yang masih punya transaksi tetapi belum di-EOD,
     * atau tanggal kalender bila tidak ada. Transaksi tidak lagi ditolak saat EOD tertunda,
     * melainkan dicatat pada tanggal bisnis yang belum ditutup itu.
     */
    public static function postingDate(string $storeId, string $calendarDate): string
    {
        return static::unclosedPriorDate($storeId, $calendarDate) ?? $calendarDate;
    }

    /**
     * Tanggal bisnis tertua yang belum ditutup untuk store (lebih lama dari $date).
     */
    public static function unclosedPriorDate(string $storeId, string $date): ?string
    {
        $prior = Sale::query()
            ->where('store_id', $storeId)
            ->where('business_date', '<', $date)
            ->distinct()
            ->pluck('business_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->sort()
            ->values();

        $closed = static::query()
            ->where('store_id', $storeId)
            ->whereNotNull('closed_at')
            ->pluck('business_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString());

        foreach ($prior as $d) {
            if (! $closed->contains($d)) {
                return $d;
            }
        }

        return null;
    }
}
