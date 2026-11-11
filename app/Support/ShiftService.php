<?php

namespace App\Support;

use App\Models\CashierShift;
use App\Models\CompanyDetail;
use App\Models\Currency;
use App\Models\PosSession;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreClosure;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan Open Store dan shift kasir. Toko dibuka sekali per business date oleh kasir pertama; tiap kasir
 * memulai shift dengan menghitung modal awal (laci uang sendiri) dan mengakhirinya saat logout, kedaluwarsa,
 * atau di-force end. Menutup toko tetap lewat EOD, bukan lewat shift.
 */
class ShiftService
{
    /**
     * Status gerbang POS untuk kasir pada tanggal posting toko.
     *
     * @return array{state: string, has_shift: bool, opened_by: ?string, opened_at: ?string, posting_date: string, opening_float_standard: float, count_by_denomination: bool, denominations: list<float>}
     */
    public static function gate(Store $store, User $user): array
    {
        static::expireStaleShifts($store->id);

        $date = static::postingDate($store);
        $closure = StoreClosure::with('openedBy')->where('store_id', $store->id)->whereDate('business_date', $date)->first();

        $state = match (true) {
            $closure?->closed_at !== null => 'eod_done',
            $closure?->opened_at !== null => 'open',
            default => 'not_opened',
        };

        $byDenomination = StoreClosure::requiresDenominationCount($store, $store->company_id);

        return [
            'state' => $state,
            'has_shift' => static::hasActiveShift($user, $store->id),
            'opened_by' => $closure?->openedBy?->name,
            'opened_at' => $closure?->opened_at?->toIso8601String(),
            'posting_date' => $date,
            'opening_float_standard' => $store->openingFloatStandard(),
            'count_by_denomination' => $byDenomination,
            'denominations' => $byDenomination ? static::denominationValues($store)->all() : [],
        ];
    }

    /**
     * Shift aktif kasir untuk dialog logout dan header POS.
     *
     * @return array{id: string, started_at: string, opened_store: bool, sales_count: int}|null
     */
    public static function summary(User $user, Store $store): ?array
    {
        $shift = CashierShift::active()->where('user_id', $user->id)->where('store_id', $store->id)->latest('started_at')->first();

        if (! $shift) {
            return null;
        }

        return [
            'id' => $shift->id,
            'started_at' => $shift->started_at->toIso8601String(),
            'opened_store' => $shift->opened_store,
            'sales_count' => Sale::where('store_id', $store->id)
                ->where('cashier_id', $user->id)
                ->where('status', Sale::STATUS_COMPLETED)
                ->where('created_at', '>=', $shift->started_at)
                ->count(),
        ];
    }

    /**
     * Buka toko untuk tanggal posting sekaligus memulai shift kasir pertama.
     *
     * @param  array{denominations?: array<int|string, int|string>, amount?: int|float|string, remark?: ?string}  $count
     */
    public static function openStore(Store $store, User $user, array $count): CashierShift
    {
        return static::begin($store, $user, $count, opening: true);
    }

    /**
     * Mulai shift kasir pada toko yang sudah dibuka. Shift aktif yang sudah ada dikembalikan apa adanya.
     *
     * @param  array{denominations?: array<int|string, int|string>, amount?: int|float|string, remark?: ?string}  $count
     */
    public static function startShift(Store $store, User $user, array $count): CashierShift
    {
        return static::begin($store, $user, $count, opening: false);
    }

    public static function endShift(User $user, string $reason): ?CashierShift
    {
        $shift = CashierShift::active()->where('user_id', $user->id)->latest('started_at')->first();

        if ($shift) {
            static::end($shift, $reason);
        }

        return $shift;
    }

    /**
     * Akhiri semua shift aktif toko (Force End Shift oleh Owner/Store Manager); mengembalikan jumlah shift.
     */
    public static function endActiveShifts(string $storeId, string $reason): int
    {
        $shifts = static::activeShifts($storeId);
        $shifts->each(fn (CashierShift $shift) => static::end($shift, $reason));

        return $shifts->count();
    }

    /**
     * Shift aktif yang kasirnya tidak lagi punya sesi POS dengan detak segar dianggap kedaluwarsa.
     */
    public static function expireStaleShifts(string $storeId): void
    {
        static::activeShifts($storeId)->each(function (CashierShift $shift) {
            $alive = PosSession::where('user_id', $shift->user_id)
                ->whereNull('ended_at')
                ->where('last_seen_at', '>=', now()->subSeconds(PosSession::STALE_AFTER_SECONDS))
                ->exists();

            if (! $alive) {
                static::end($shift, CashierShift::ENDED_EXPIRED);
            }
        });
    }

    /**
     * @return Collection<int, CashierShift>
     */
    public static function activeShifts(string $storeId): Collection
    {
        return CashierShift::active()->with('user')->where('store_id', $storeId)->orderBy('started_at')->get();
    }

    public static function hasActiveShift(User $user, string $storeId): bool
    {
        return CashierShift::active()->where('user_id', $user->id)->where('store_id', $storeId)->exists();
    }

    /**
     * Jumlah modal awal semua shift pada tanggal bisnis (bagian dari kas yang harus disetor di EOD).
     */
    public static function openingFloatTotal(string $storeId, string $date): float
    {
        return round((float) CashierShift::where('store_id', $storeId)->whereDate('business_date', $date)->sum('opening_float'), 2);
    }

    public static function postingDate(Store $store): string
    {
        return StoreClosure::postingDate($store->id, company_today());
    }

    /**
     * @param  array{denominations?: array<int|string, int|string>, amount?: int|float|string, remark?: ?string}  $count
     */
    private static function begin(Store $store, User $user, array $count, bool $opening): CashierShift
    {
        $date = static::postingDate($store);
        $float = static::resolveFloat($store, $count);

        $shift = DB::transaction(function () use ($store, $user, $date, $float, $opening) {
            $closure = StoreClosure::where('store_id', $store->id)->whereDate('business_date', $date)->lockForUpdate()->first();

            if ($closure?->closed_at) {
                throw new DomainException("EOD for {$date} is already closed.");
            }

            if ($opening) {
                if ($closure?->opened_at) {
                    throw new DomainException("The store is already open for {$date}.");
                }

                $attributes = ['opened_at' => now(), 'opened_by' => $user->id];
                $closure ? $closure->update($attributes) : StoreClosure::create($attributes + [
                    'company_id' => $store->company_id,
                    'store_id' => $store->id,
                    'business_date' => $date,
                ]);
            } else {
                if (! $closure?->opened_at) {
                    throw new DomainException("The store is not open for {$date}.");
                }

                $existing = CashierShift::active()->where('user_id', $user->id)->where('store_id', $store->id)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $shift = CashierShift::create([
                'company_id' => $store->company_id,
                'store_id' => $store->id,
                'user_id' => $user->id,
                'business_date' => $date,
                'started_at' => now(),
                'opened_store' => $opening,
                'opening_float' => $float['total'],
                'opening_float_expected' => $float['standard'],
                'opening_float_variance' => $float['variance'],
                'opening_float_remark' => $float['remark'],
            ]);

            foreach ($float['rows'] as $row) {
                $shift->denominations()->create($row);
            }

            return $shift;
        });

        if ($shift->wasRecentlyCreated) {
            if ($opening) {
                ActivityLogger::log('store.opened', "Toko {$store->code} dibuka untuk tanggal {$date} oleh {$user->name}", $store);
            }

            ActivityLogger::log(
                'shift.started',
                "Shift {$user->name} dimulai di {$store->code} ({$date}), modal awal Rp ".number_format($float['total'], 0, ',', '.'),
                $shift,
                ['opening_float' => $float['total'], 'expected' => $float['standard'], 'variance' => $float['variance'], 'remark' => $float['remark']],
            );
        }

        return $shift;
    }

    private static function end(CashierShift $shift, string $reason): void
    {
        $shift->update(['ended_at' => now(), 'ended_reason' => $reason]);

        $name = $shift->user?->name ?? '-';
        $forced = $reason === CashierShift::ENDED_CLOSED_BY_ADMIN;

        ActivityLogger::log(
            $forced ? 'shift.force_ended' : 'shift.ended',
            $forced ? "Shift {$name} diakhiri paksa" : "Shift {$name} berakhir ({$reason})",
            $shift,
            ['reason' => $reason],
        );
    }

    /**
     * Hitung modal awal dari input kasir: per denominasi (Count Type Detail) atau satu nominal (Summary).
     * Selisih dari modal standar toko wajib diberi remark.
     *
     * @param  array{denominations?: array<int|string, int|string>, amount?: int|float|string, remark?: ?string}  $count
     * @return array{total: float, standard: float, variance: float, remark: ?string, rows: list<array{denomination_value: float, count: int, subtotal: float}>}
     */
    private static function resolveFloat(Store $store, array $count): array
    {
        $rows = [];
        $total = 0.0;

        if (StoreClosure::requiresDenominationCount($store, $store->company_id)) {
            $input = $count['denominations'] ?? null;

            if (! is_array($input)) {
                throw ValidationException::withMessages(['denominations' => 'Count the opening float per denomination.']);
            }

            $allowed = static::denominationValues($store)->map(fn ($value) => (float) $value)->all();

            foreach ($input as $value => $quantity) {
                $quantity = (int) $quantity;

                if (! in_array((float) $value, $allowed, true) || $quantity < 0) {
                    throw ValidationException::withMessages(['denominations' => 'Invalid denomination or quantity.']);
                }

                if ($quantity === 0) {
                    continue;
                }

                $subtotal = (float) $value * $quantity;
                $total += $subtotal;
                $rows[] = ['denomination_value' => (float) $value, 'count' => $quantity, 'subtotal' => $subtotal];
            }
        } else {
            if (! isset($count['amount']) || ! is_numeric($count['amount']) || (float) $count['amount'] < 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the opening float amount.']);
            }

            $total = (float) $count['amount'];
        }

        $total = round($total, 2);
        $standard = $store->openingFloatStandard();
        $variance = round($total - $standard, 2);
        $remark = isset($count['remark']) ? trim((string) $count['remark']) : null;
        $remark = $remark === '' ? null : $remark;

        if ($variance !== 0.0 && $remark === null) {
            throw ValidationException::withMessages(['remark' => 'Explain the difference from the standard opening float.']);
        }

        return ['total' => $total, 'standard' => $standard, 'variance' => $variance, 'remark' => $remark, 'rows' => $rows];
    }

    /**
     * Denominasi aktif mata uang dasar, dari yang terbesar.
     *
     * @return Collection<int, float>
     */
    private static function denominationValues(Store $store): Collection
    {
        $company = CompanyDetail::find($store->company_id);

        return Currency::where('company_id', $store->company_id)
            ->where('code', $company?->base_currency_code)
            ->first()
            ?->denominations()
            ->where('is_active', true)
            ->orderByDesc('value')
            ->pluck('value')
            ->map(fn ($value) => (float) $value)
            ->values() ?? collect();
    }
}
