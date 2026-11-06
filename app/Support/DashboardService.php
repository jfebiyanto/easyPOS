<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data section "Penjualan Hari Ini" di dashboard admin (satu sumber untuk semua kartu).
 * Pengelompokan per jam dilakukan di PHP dalam zona waktu perusahaan agar sama di PostgreSQL & SQLite.
 */
class DashboardService
{
    private const TOP_LIMIT = 5;

    private const TARGET_DAYS = 7;

    /**
     * @return array{
     *     date: string, timezone: string, generated_at: string, hours: array<int, string>,
     *     sales: array{total: float, transactions: int, delta_pct: ?float, hourly: array<int, float>, top_cashiers: array<int, array{name: string, total: float, transactions: int}>},
     *     products: array{top: ?array{name: string, sku: ?string, qty: float, omzet: float, delta_pct: ?float}, hourly: array<int, float>, list: array<int, array{name: string, sku: ?string, qty: float, omzet: float, share_pct: float}>},
     *     performance: array{net_sales: float, net_delta_pct: ?float, net_target_pct: ?float, deductions: float, deductions_delta_pct: ?float, deductions_pct: float, target_total: float, income_hourly: array<int, float>, expense_hourly: array<int, float>, target_hourly: array<int, float>}
     * }
     */
    public static function today(string $storeId): array
    {
        $timezone = company_timezone();
        $today = company_today();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        $sales = self::sales($storeId, Carbon::parse($today)->subDays(self::TARGET_DAYS)->toDateString(), $today, $timezone);
        $todaySales = $sales->where('business_date', $today);
        $yesterdaySales = $sales->where('business_date', $yesterday);

        $returns = self::returns($storeId, $today, $timezone);
        $yesterdayReturns = self::returns($storeId, $yesterday, $timezone);

        $lines = self::lines($storeId, [$today, $yesterday]);
        $todayLines = $lines->where('business_date', $today);
        $products = self::rankProducts($todayLines);
        $topProduct = $products->first();

        $gross = (float) $todaySales->sum('total');
        $discounts = (float) $todaySales->sum('discount_total');
        $deductions = (float) $returns->sum('total') + $discounts;
        $netSales = $gross - (float) $returns->sum('total');
        $yesterdayNet = (float) $yesterdaySales->sum('total') - (float) $yesterdayReturns->sum('total');
        $yesterdayDeductions = (float) $yesterdayReturns->sum('total') + (float) $yesterdaySales->sum('discount_total');

        $targetHourly = self::targetHourly($sales->where('business_date', '<', $today));
        $targetTotal = array_sum($targetHourly);

        return [
            'date' => $today,
            'timezone' => $timezone,
            'generated_at' => now()->toIso8601String(),
            'hours' => array_map(fn (int $h) => sprintf('%02d:00', $h), range(0, 23)),
            'sales' => [
                'total' => $gross,
                'transactions' => $todaySales->count(),
                'delta_pct' => self::deltaPct($gross, (float) $yesterdaySales->sum('total')),
                'hourly' => self::hourly($todaySales, 'total'),
                'top_cashiers' => $todaySales->groupBy('cashier_name')
                    ->map(fn (Collection $rows, string $name) => [
                        'name' => $name,
                        'total' => (float) $rows->sum('total'),
                        'transactions' => $rows->count(),
                    ])
                    ->sortByDesc('total')->take(self::TOP_LIMIT)->values()->all(),
            ],
            'products' => [
                'top' => $topProduct ? [
                    'name' => $topProduct['name'],
                    'sku' => $topProduct['sku'],
                    'qty' => $topProduct['qty'],
                    'omzet' => $topProduct['omzet'],
                    'delta_pct' => self::deltaPct(
                        $topProduct['omzet'],
                        (float) $lines->where('business_date', $yesterday)->where('key', $topProduct['key'])->sum('line_total'),
                    ),
                ] : null,
                'hourly' => $topProduct ? self::hourly($todayLines->where('key', $topProduct['key']), 'line_total') : array_fill(0, 24, 0.0),
                'list' => $products->take(self::TOP_LIMIT)->map(fn (array $p) => [
                    'name' => $p['name'],
                    'sku' => $p['sku'],
                    'qty' => $p['qty'],
                    'omzet' => $p['omzet'],
                    'share_pct' => $gross > 0 ? round($p['omzet'] / $gross * 100, 1) : 0.0,
                ])->values()->all(),
            ],
            'performance' => [
                'net_sales' => $netSales,
                'net_delta_pct' => self::deltaPct($netSales, $yesterdayNet),
                'net_target_pct' => $targetTotal > 0 ? round($netSales / $targetTotal * 100, 1) : null,
                'deductions' => $deductions,
                'deductions_delta_pct' => self::deltaPct($deductions, $yesterdayDeductions),
                'deductions_pct' => ($gross + $discounts) > 0 ? round($deductions / ($gross + $discounts) * 100, 1) : 0.0,
                'target_total' => $targetTotal,
                'income_hourly' => self::subtract(self::hourly($todaySales, 'total'), self::hourly($returns, 'total')),
                'expense_hourly' => self::add(self::hourly($returns, 'total'), self::hourly($todaySales, 'discount_total')),
                'target_hourly' => $targetHourly,
            ],
        ];
    }

    /**
     * Ringkasan hari ini untuk sekumpulan gerai: jumlah transaksi penjualan selesai (tanggal bisnis hari ini),
     * jumlah transaksi retur (dibuat pada tanggal lokal hari ini), dan penjualan per gerai untuk perbandingan.
     *
     * @param  Collection<int, Store>  $stores
     * @return array{date: string, stores: array<int, array{code: string, name: string, total: float, transactions: int}>, sales_transactions: int, returns_transactions: int}
     */
    public static function overview(Collection $stores): array
    {
        $timezone = company_timezone();
        $today = company_today();
        $storeIds = $stores->pluck('id')->all();
        $start = Carbon::parse($today, $timezone)->startOfDay();

        $perStore = Sale::query()
            ->whereIn('store_id', $storeIds)
            ->where('status', Sale::STATUS_COMPLETED)
            ->whereDate('business_date', $today)
            ->selectRaw('store_id, count(*) as transactions, coalesce(sum(total), 0) as total')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $returnsCount = SalesReturn::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$start->copy()->utc(), $start->copy()->endOfDay()->utc()])
            ->count();

        $rows = $stores->map(fn (Store $store) => [
            'code' => $store->code,
            'name' => $store->name,
            'total' => (float) ($perStore->get($store->id)->total ?? 0),
            'transactions' => (int) ($perStore->get($store->id)->transactions ?? 0),
        ])->sort(fn (array $a, array $b) => [$b['total'], $a['code']] <=> [$a['total'], $b['code']])->values()->all();

        return [
            'date' => $today,
            'stores' => $rows,
            'sales_transactions' => array_sum(array_column($rows, 'transactions')),
            'returns_transactions' => $returnsCount,
        ];
    }

    /**
     * Penjualan selesai dalam rentang tanggal bisnis, dengan jam lokal & nama kasir.
     */
    private static function sales(string $storeId, string $from, string $to, string $timezone): Collection
    {
        return Sale::query()
            ->leftJoin('users', 'users.id', '=', 'sales.cashier_id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereDate('sales.business_date', '>=', $from)
            ->whereDate('sales.business_date', '<=', $to)
            ->get(['sales.business_date', 'sales.total', 'sales.discount_total', 'sales.sold_at', 'sales.created_at', 'users.name as cashier_name'])
            ->map(fn (Sale $s) => [
                'business_date' => $s->business_date->toDateString(),
                'total' => (float) $s->total,
                'discount_total' => (float) $s->discount_total,
                'hour' => ($s->sold_at ?? $s->created_at)->copy()->setTimezone($timezone)->hour,
                'cashier_name' => $s->cashier_name ?? 'No cashier',
            ]);
    }

    /**
     * Retur yang dibuat pada tanggal lokal tersebut.
     */
    private static function returns(string $storeId, string $date, string $timezone): Collection
    {
        $start = Carbon::parse($date, $timezone)->startOfDay();

        return SalesReturn::query()
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$start->copy()->utc(), $start->copy()->endOfDay()->utc()])
            ->get(['total', 'created_at'])
            ->map(fn (SalesReturn $r) => [
                'total' => (float) $r->total,
                'hour' => $r->created_at->copy()->setTimezone($timezone)->hour,
            ]);
    }

    /**
     * @param  array<int, string>  $dates
     */
    private static function lines(string $storeId, array $dates): Collection
    {
        $timezone = company_timezone();

        return DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_lines.product_id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where(fn ($q) => collect($dates)->each(fn (string $d) => $q->orWhereDate('sales.business_date', $d)))
            ->get(['sales.business_date', 'sales.sold_at', 'sales.created_at', 'sale_lines.product_id', 'sale_lines.sku_snapshot', 'sale_lines.name_snapshot', 'sale_lines.qty', 'sale_lines.line_total', 'products.name as product_name', 'products.sku as product_sku'])
            ->map(fn (object $l) => [
                'business_date' => Carbon::parse($l->business_date)->toDateString(),
                'key' => $l->product_id ?? $l->sku_snapshot,
                'name' => $l->product_name ?? $l->name_snapshot,
                'sku' => $l->product_sku ?? $l->sku_snapshot,
                'qty' => (float) $l->qty,
                'line_total' => (float) $l->line_total,
                'hour' => Carbon::parse($l->sold_at ?? $l->created_at, 'UTC')->setTimezone($timezone)->hour,
            ]);
    }

    private static function rankProducts(Collection $lines): Collection
    {
        return $lines->groupBy('key')
            ->map(fn (Collection $rows, string $key) => [
                'key' => $key,
                'name' => $rows->first()['name'],
                'sku' => $rows->first()['sku'],
                'qty' => (float) $rows->sum('qty'),
                'omzet' => (float) $rows->sum('line_total'),
            ])
            ->sortByDesc('omzet')
            ->values();
    }

    /**
     * Target per jam = rata-rata penjualan per jam selama 7 hari sebelumnya.
     *
     * @return array<int, float>
     */
    private static function targetHourly(Collection $pastSales): array
    {
        return array_map(fn (float $v) => round($v / self::TARGET_DAYS, 2), self::hourly($pastSales, 'total'));
    }

    /**
     * @return array<int, float>
     */
    private static function hourly(Collection $rows, string $field): array
    {
        $hours = array_fill(0, 24, 0.0);
        foreach ($rows as $row) {
            $hours[$row['hour']] += (float) $row[$field];
        }

        return $hours;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     * @return array<int, float>
     */
    private static function add(array $a, array $b): array
    {
        return array_map(fn (float $x, float $y) => $x + $y, $a, $b);
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     * @return array<int, float>
     */
    private static function subtract(array $a, array $b): array
    {
        return array_map(fn (float $x, float $y) => $x - $y, $a, $b);
    }

    private static function deltaPct(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }
}
