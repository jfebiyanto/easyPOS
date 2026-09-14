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
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function denominations()
    {
        return $this->hasMany(StoreClosureDenomination::class);
    }

    /**
     * Tanggal bisnis terakhir yang belum ditutup untuk store (lebih lama dari $date).
     */
    public static function unclosedPriorDate(string $storeId, string $date): ?string
    {
        $prior = Sale::query()
            ->where('store_id', $storeId)
            ->where('business_date', '<', $date)
            ->distinct()
            ->pluck('business_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique();

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
