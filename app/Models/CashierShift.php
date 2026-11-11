<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift satu kasir pada satu business date: dimulai dengan menghitung modal awal (laci uang sendiri)
 * dan berakhir saat logout, kedaluwarsa, atau di-force end oleh Owner/Store Manager.
 */
class CashierShift extends Model
{
    use HasUuid7;

    public const ENDED_LOGOUT = 'logout';

    public const ENDED_EXPIRED = 'expired';

    public const ENDED_CLOSED_BY_ADMIN = 'closed_by_admin';

    public const ENDED_LABELS = [
        self::ENDED_LOGOUT => 'Logged out',
        self::ENDED_EXPIRED => 'Expired',
        self::ENDED_CLOSED_BY_ADMIN => 'Force ended',
    ];

    protected $fillable = [
        'company_id', 'store_id', 'user_id', 'business_date', 'started_at', 'ended_at', 'ended_reason', 'opened_store',
        'opening_float', 'opening_float_expected', 'opening_float_variance', 'opening_float_remark',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'opened_store' => 'boolean',
            'opening_float' => 'decimal:2',
            'opening_float_expected' => 'decimal:2',
            'opening_float_variance' => 'decimal:2',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function denominations(): HasMany
    {
        return $this->hasMany(CashierShiftDenomination::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
