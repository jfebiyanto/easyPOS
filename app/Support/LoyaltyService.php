<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyLedger;
use App\Models\MemberLevel;
use App\Models\Sale;
use App\Models\SalesReturn;

/**
 * Program loyalitas: perolehan poin per transaksi, kenaikan level otomatis,
 * pembalikan saat retur/void. Poin belum dapat ditukar (redeem) di MVP.
 */
class LoyaltyService
{
    public static function config(?string $companyId = null): LoyaltyConfig
    {
        return LoyaltyConfig::current($companyId);
    }

    public static function levelFor(string $companyId, float $points): ?MemberLevel
    {
        return MemberLevel::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('min_points', '<=', $points)
            ->orderByDesc('min_points')
            ->first();
    }

    public static function earnRate(Customer $customer, string $companyId): float
    {
        $level = $customer->memberLevel ?? self::levelFor($companyId, (float) $customer->points_balance);

        return max(1.0, (float) ($level?->earn_amount_per_point ?: 10000));
    }

    public static function roundPoints(float $points, string $mode): float
    {
        return match ($mode) {
            'up' => (float) ceil($points),
            'nearest' => (float) round($points),
            'none' => round($points, 2),
            default => (float) floor($points),
        };
    }

    public static function earnForSale(Sale $sale): void
    {
        if (! $sale->customer_id || $sale->isVoid()) {
            return;
        }

        $customer = Customer::find($sale->customer_id);
        if (! $customer || ! $customer->is_member) {
            return;
        }

        $config = self::config($sale->company_id);
        if (! $config->is_active || (float) $sale->total < (float) $config->min_spend) {
            return;
        }

        $points = self::roundPoints((float) $sale->total / self::earnRate($customer, $sale->company_id), $config->rounding);

        if ($points <= 0) {
            $sale->update(['points_earned' => 0]);

            return;
        }

        self::apply($customer, 'earn', $points, 'sale', $sale->id, "Poin transaksi {$sale->number}", $sale->cashier_id);
        $sale->update(['points_earned' => $points]);
    }

    public static function reverseForReturn(SalesReturn $return): void
    {
        $sale = $return->originalSale;
        if (! $sale || ! $sale->customer_id) {
            return;
        }

        $customer = Customer::find($sale->customer_id);
        if (! $customer) {
            return;
        }

        $config = self::config($sale->company_id);
        $points = self::roundPoints((float) $return->total / self::earnRate($customer, $sale->company_id), $config->rounding);

        if ($points <= 0) {
            return;
        }

        self::apply($customer, 'return', -$points, 'sales_return', $return->id, "Retur {$return->number}", $return->created_by);
    }

    public static function reverseForVoid(Sale $sale): void
    {
        if (! $sale->customer_id || (float) $sale->points_earned <= 0) {
            return;
        }

        $customer = Customer::find($sale->customer_id);
        if (! $customer) {
            return;
        }

        self::apply($customer, 'adjust', -(float) $sale->points_earned, 'sale_void', $sale->id, "Void {$sale->number}", null);
    }

    public static function adjust(Customer $customer, float $points, ?string $note, ?string $userId): void
    {
        self::apply($customer, 'adjust', $points, 'manual', null, $note, $userId);
    }

    protected static function apply(
        Customer $customer,
        string $type,
        float $points,
        ?string $refType,
        ?string $refId,
        ?string $note,
        ?string $userId
    ): void {
        $newBalance = max(0, (float) $customer->points_balance + $points);

        $customer->update(['points_balance' => $newBalance]);

        $level = self::levelFor($customer->company_id, $newBalance);
        $customer->update(['member_level_id' => $level?->id]);

        LoyaltyLedger::create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'type' => $type,
            'points' => $points,
            'balance_after' => $newBalance,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }
}
