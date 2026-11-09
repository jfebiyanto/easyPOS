<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasUuid7;

    public const SOURCE_SAP = 'sap';

    public const SOURCE_MANUAL = 'manual';

    public const SYNC_PENDING = 'pending';

    public const SYNC_SYNCED = 'synced';

    public const SYNC_FAILED = 'failed';

    /**
     * Field yang dikuasai SAP B1: hanya-baca untuk customer dari SAP, dan dikirim ke SAP
     * untuk customer manual.
     *
     * @var list<string>
     */
    public const SAP_FIELDS = ['name', 'phone', 'email', 'address', 'customer_group_id'];

    protected $fillable = [
        'company_id', 'code', 'name', 'phone', 'email', 'address', 'is_member', 'is_retail',
        'member_level_id', 'customer_group_id', 'points_balance', 'joined_at', 'is_active',
        'source', 'sap_sync_status', 'sap_sync_error', 'sap_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_member' => 'boolean',
            'is_retail' => 'boolean',
            'is_active' => 'boolean',
            'points_balance' => 'decimal:2',
            'joined_at' => 'datetime',
            'sap_synced_at' => 'datetime',
        ];
    }

    public function isFromSap(): bool
    {
        return $this->source === self::SOURCE_SAP;
    }

    /**
     * Calon pelanggan retail default gerai (non-member). Selama integrasi SAP aktif hanya customer
     * retail dari SAP (U_SOL_RETAIL = 'Y'); tanpa integrasi, semua customer non-member.
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeRetailDefaultCandidates(Builder $query, bool $sapActive): void
    {
        $query->where('is_member', false)
            ->when($sapActive, fn (Builder $q) => $q->where('is_retail', true));
    }

    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function memberLevel()
    {
        return $this->belongsTo(MemberLevel::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function loyaltyLedger()
    {
        return $this->hasMany(LoyaltyLedger::class);
    }
}
