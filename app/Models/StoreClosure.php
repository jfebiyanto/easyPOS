<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function payments()
    {
        return $this->hasMany(StoreClosurePayment::class);
    }

    public function denominations()
    {
        return $this->hasMany(StoreClosureDenomination::class);
    }

    /**
     * Nominal yang diharapkan per tipe pembayaran (id tipe => nominal) pada tanggal bisnis: pembayaran
     * terkonfirmasi transaksi selesai. Kembalian dikurangkan dari pembayaran tunai (jenis cash) transaksi
     * itu, mulai dari yang terbesar, karena kembalian selalu keluar dari laci kas.
     *
     * @return array<string, float>
     */
    public static function expectedByPaymentType(string $storeId, string $date): array
    {
        $rows = DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->join('payment_types', 'payment_types.id', '=', 'sale_payments.payment_type_id')
            ->where('sales.store_id', $storeId)
            ->whereDate('sales.business_date', $date)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('sale_payments.status', 'confirmed')
            ->get(['sales.id as sale_id', 'sales.change_total', 'sale_payments.payment_type_id', 'sale_payments.amount', 'payment_types.kind']);

        $expected = [];
        foreach ($rows->groupBy('sale_id') as $salePayments) {
            $change = (float) $salePayments->first()->change_total;

            foreach ($salePayments->sortByDesc(fn ($row) => $row->kind === PaymentType::KIND_CASH ? (float) $row->amount : -1) as $row) {
                $amount = (float) $row->amount;

                if ($row->kind === PaymentType::KIND_CASH && $change > 0) {
                    $deducted = min($change, $amount);
                    $amount -= $deducted;
                    $change -= $deducted;
                }

                $expected[$row->payment_type_id] = round(($expected[$row->payment_type_id] ?? 0) + $amount, 2);
            }
        }

        return $expected;
    }

    /**
     * Kas tunai yang seharusnya ada di laci pada tanggal bisnis (jumlah tipe jenis cash, setelah kembalian).
     */
    public static function expectedCash(string $storeId, string $companyId, string $date): float
    {
        $cashTypeIds = PaymentType::where('company_id', $companyId)->where('kind', PaymentType::KIND_CASH)->pluck('id')->all();

        return round(array_sum(array_intersect_key(static::expectedByPaymentType($storeId, $date), array_flip($cashTypeIds))), 2);
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
