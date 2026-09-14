<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agregasi laporan penjualan. Omzet dihitung dari transaksi selesai (bukan void)
 * dikurangi retur pada periode yang sama.
 */
class ReportService
{
    public const REPORTS = [
        'sales_by_store' => 'Penjualan per Gerai',
        'sales_by_sku' => 'Penjualan per SKU',
        'best_sellers' => 'Produk Terlaris',
        'customer_spend' => 'Rata-rata Belanja Pelanggan',
    ];

    public static function range(?string $from, ?string $to): array
    {
        $from = $from ? Carbon::parse($from)->toDateString() : now()->startOfMonth()->toDateString();
        $to = $to ? Carbon::parse($to)->toDateString() : now()->toDateString();

        return [$from, $to];
    }

    public static function build(string $report, string $from, string $to, array $storeIds): array
    {
        abort_unless(array_key_exists($report, self::REPORTS), 404, 'Laporan tidak dikenal.');

        $data = match ($report) {
            'sales_by_store' => self::salesByStore($from, $to, $storeIds),
            'sales_by_sku' => self::salesBySku($from, $to, $storeIds),
            'best_sellers' => self::bestSellers($from, $to, $storeIds),
            'customer_spend' => self::customerSpend($from, $to, $storeIds),
        };

        return array_merge([
            'report' => $report,
            'title' => self::REPORTS[$report],
            'from' => $from,
            'to' => $to,
        ], $data);
    }

    private static function salesByStore(string $from, string $to, array $storeIds): array
    {
        $sales = Sale::whereIn('store_id', $storeIds)
            ->where('status', Sale::STATUS_COMPLETED)
            ->where('business_date', '>=', $from)->where('business_date', '<=', $to.' 23:59:59')
            ->selectRaw('store_id, count(*) as txn, sum(subtotal) as subtotal, sum(tax_total) as tax, sum(total) as total')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $returns = SalesReturn::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('store_id, sum(total) as total')
            ->groupBy('store_id')
            ->pluck('total', 'store_id');

        $stores = Store::whereIn('id', $storeIds)->orderBy('code')->get();

        $rows = $stores->map(function (Store $store) use ($sales, $returns) {
            $s = $sales->get($store->id);
            $gross = (float) ($s->total ?? 0);
            $ret = (float) ($returns[$store->id] ?? 0);

            return [
                $store->code.' · '.$store->name,
                (int) ($s->txn ?? 0),
                (float) ($s->subtotal ?? 0),
                (float) ($s->tax ?? 0),
                $gross,
                $ret,
                $gross - $ret,
            ];
        })->all();

        return [
            'columns' => ['Gerai', 'Transaksi', 'Subtotal', 'PPN', 'Omzet', 'Retur', 'Net'],
            'rows' => $rows,
            'numeric' => [1, 2, 3, 4, 5, 6],
        ];
    }

    private static function salesBySku(string $from, string $to, array $storeIds): array
    {
        $aggregated = self::aggregateSku($from, $to, $storeIds);

        $rows = [];
        foreach ($aggregated as $item) {
            if ($item['qty'] == 0 && $item['omzet'] == 0) {
                continue;
            }
            $rows[] = [$item['sku'], $item['name'], $item['qty'], $item['omzet']];
        }

        usort($rows, fn ($a, $b) => $b[2] <=> $a[2]);

        return ['columns' => ['SKU', 'Produk', 'Qty', 'Omzet'], 'rows' => $rows, 'numeric' => [2, 3]];
    }

    private static function bestSellers(string $from, string $to, array $storeIds): array
    {
        $data = self::salesBySku($from, $to, $storeIds);
        $data['rows'] = array_slice($data['rows'], 0, 20);
        $data['title'] = 'Produk Terlaris (Top 20)';

        return $data;
    }

    private static function customerSpend(string $from, string $to, array $storeIds): array
    {
        $sales = Sale::whereIn('store_id', $storeIds)
            ->where('status', Sale::STATUS_COMPLETED)
            ->where('business_date', '>=', $from)->where('business_date', '<=', $to.' 23:59:59')
            ->get(['customer_id', 'total']);

        $returns = DB::table('sales_returns')
            ->join('sales', 'sales.id', '=', 'sales_returns.original_sale_id')
            ->whereIn('sales.store_id', $storeIds)
            ->whereBetween('sales_returns.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get(['sales.customer_id', 'sales_returns.total']);

        $bucket = [];
        foreach ($sales as $s) {
            $key = $s->customer_id ?? '__anon__';
            $bucket[$key] ??= ['txn' => 0, 'total' => 0.0];
            $bucket[$key]['txn']++;
            $bucket[$key]['total'] += (float) $s->total;
        }
        foreach ($returns as $r) {
            $key = $r->customer_id ?? '__anon__';
            if (isset($bucket[$key])) {
                $bucket[$key]['total'] -= (float) $r->total;
            }
        }

        $names = Customer::whereIn('id', array_filter(array_keys($bucket)))->pluck('name', 'id');

        $rows = [];
        foreach ($bucket as $key => $data) {
            $name = $key === '__anon__' ? 'Anonim (tanpa customer ID)' : ($names[$key] ?? $key);
            $avg = $data['txn'] > 0 ? round($data['total'] / $data['txn'], 2) : 0;
            $rows[] = [$name, $data['txn'], round($data['total'], 2), $avg];
        }

        usort($rows, fn ($a, $b) => $b[2] <=> $a[2]);

        return ['columns' => ['Pelanggan', 'Transaksi', 'Total Belanja', 'Rata-rata'], 'rows' => $rows, 'numeric' => [1, 2, 3]];
    }

    /**
     * @return array<string,array{sku:string,name:string,qty:float,omzet:float}>
     */
    private static function aggregateSku(string $from, string $to, array $storeIds): array
    {
        $lines = DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->whereIn('sales.store_id', $storeIds)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('sales.business_date', '>=', $from)
            ->where('sales.business_date', '<=', $to.' 23:59:59')
            ->get(['sale_lines.product_id', 'sale_lines.sku_snapshot', 'sale_lines.name_snapshot', 'sale_lines.qty', 'sale_lines.line_total']);

        $returnLines = DB::table('sales_return_lines')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
            ->whereIn('sales_returns.store_id', $storeIds)
            ->whereBetween('sales_returns.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get(['sales_return_lines.product_id', 'sales_return_lines.qty', 'sales_return_lines.line_total']);

        $out = [];
        foreach ($lines as $l) {
            $key = $l->product_id ?? $l->sku_snapshot;
            $out[$key] ??= ['sku' => $l->sku_snapshot, 'name' => $l->name_snapshot, 'qty' => 0.0, 'omzet' => 0.0];
            $out[$key]['qty'] += (float) $l->qty;
            $out[$key]['omzet'] += (float) $l->line_total;
        }
        foreach ($returnLines as $l) {
            $key = $l->product_id;
            if (isset($out[$key])) {
                $out[$key]['qty'] -= (float) $l->qty;
                $out[$key]['omzet'] -= (float) $l->line_total;
            }
        }

        return $out;
    }
}
