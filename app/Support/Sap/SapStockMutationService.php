<?php

namespace App\Support\Sap;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\WarehouseStockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Salin mutasi stok SAP B1 (log OIVL) ke buku besar warehouse easyPOS, baris per baris,
 * sehingga riwayat dan saldo on_hand identik dengan SAP.
 *
 * - Kunci unik baris = TransSeq; cursor = TransSeq terbesar yang sudah tersalin.
 * - Hemat bandwidth: sinkronisasi per menit hanya meminta TransSeq di atas cursor (hanya
 *   mutasi baru; tanpa pergerakan respons kosong). Pembacaan ulang LOOKBACK TransSeq terakhir,
 *   agar baris yang ter-commit tidak berurutan di SAP tidak terlewat, dijalankan tiap
 *   LOOKBACK_EVERY_MINUTES dan pada sinkronisasi master; baris yang sudah ada dilewati.
 * - Teks SQL Query di SAP hanya dicek saat teksnya berubah atau query tidak ditemukan.
 * - Bila item/warehouse belum ada di easyPOS, penyalinan berhenti di baris itu (tidak dilewati)
 *   sampai master data tersinkron.
 * - Harga/cost item tidak disalin: cost hanya dilihat di SAP.
 * - Sinkronisasi pertama mengenolkan on_hand lalu membangun saldo dari seluruh riwayat OIVL.
 */
class SapStockMutationService
{
    public const QUERY_CODE = 'EPOS_STOCK_MUTATION';

    public const QUERY_NAME = 'easyPOS - mutasi stok (OIVL)';

    public const QUERY_SQL = 'SELECT T0."TransSeq", T0."TransType", T0."CreatedBy", T0."BASE_REF", T0."DocLineNum", '
        .'T0."ItemCode", T0."LocCode", T0."InQty", T0."OutQty", T0."DocDate" '
        .'FROM "OIVL" T0 WHERE T0."TransSeq" > :lastTransSeq AND T0."LocType" = 64 '
        .'AND (T0."InQty" <> 0 OR T0."OutQty" <> 0) ORDER BY T0."TransSeq"';

    private const LOOKBACK = 100;

    private const LOOKBACK_EVERY_MINUTES = 10;

    /** Batas halaman per sinkronisasi; sisanya dilanjutkan pada sinkronisasi berikutnya. */
    private const MAX_PAGES = 50;

    /** @var array<string, Warehouse> kode → warehouse */
    private array $warehouses = [];

    /** @var array<string, string> sku → id */
    private array $products = [];

    public function __construct(private readonly CompanyDetail $company) {}

    /**
     * @param  bool  $wait  tunggu sinkronisasi lain selesai (impor master) alih-alih langsung berhenti
     * @param  bool  $lookback  paksa pembacaan ulang LOOKBACK TransSeq terakhir (sinkronisasi master)
     * @return array{busy: bool, fetched: int, created: int, skipped: int, halted: ?string}
     */
    public function sync(SapServiceLayer $client, bool $wait = false, bool $lookback = false): array
    {
        $result = ['busy' => false, 'fetched' => 0, 'created' => 0, 'skipped' => 0, 'halted' => null];
        $lock = Cache::lock('sap-stock-mutation:'.$this->company->id, 600);

        if (! ($wait ? $lock->block(120) : $lock->get())) {
            return ['busy' => true] + $result;
        }

        try {
            $this->ensureQuery($client);

            $hasSapRows = WarehouseStockLedger::where('company_id', $this->company->id)->whereNotNull('sap_trans_seq')->exists();
            if ($this->company->sap_stock_synced_at === null && ! $hasSapRows) {
                $this->resetBalances();
            }

            $lookbackDue = Cache::add('sap-stock-lookback:'.$this->company->id, true, now()->addMinutes(self::LOOKBACK_EVERY_MINUTES));
            $result = $this->copyMutations($client, $result, $lookback || $lookbackDue);

            $this->company->forceFill([
                'sap_stock_synced_at' => now(),
                'sap_stock_sync_error' => $result['halted'],
            ])->save();

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{busy: bool, fetched: int, created: int, skipped: int, halted: ?string}  $result
     * @return array{busy: bool, fetched: int, created: int, skipped: int, halted: ?string}
     */
    private function copyMutations(SapServiceLayer $client, array $result, bool $lookback): array
    {
        $cursor = (int) WarehouseStockLedger::where('company_id', $this->company->id)->max('sap_trans_seq');
        $from = $lookback ? max(0, $cursor - self::LOOKBACK) : $cursor;

        $known = WarehouseStockLedger::where('company_id', $this->company->id)
            ->where('sap_trans_seq', '>', $from)
            ->pluck('sap_trans_seq')
            ->flip()
            ->all();

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            ['rows' => $rows, 'hasMore' => $hasMore] = $this->runQuery($client, $from);

            foreach ($rows as $row) {
                $result['fetched']++;
                $seq = (int) $row['TransSeq'];

                if (isset($known[$seq])) {
                    $result['skipped']++;

                    continue;
                }

                $halted = $this->missingMaster($row);
                if ($halted !== null) {
                    $result['halted'] = "Mutasi stok SAP TransSeq {$seq} menunggu master data: {$halted}.";

                    return $result;
                }

                $result[$this->linkToEasyPosSale($row) || ! $this->apply($row) ? 'skipped' : 'created']++;
                $known[$seq] = true;
            }

            if (! $hasMore || $rows === []) {
                break;
            }

            $from = (int) end($rows)['TransSeq'];
        }

        return $result;
    }

    /**
     * Buat/perbarui SQL Query di SAP hanya bila teksnya berubah sejak terakhir dipastikan.
     */
    private function ensureQuery(SapServiceLayer $client, bool $force = false): void
    {
        $key = $this->queryCacheKey();

        if ($force || ! Cache::has($key)) {
            $client->ensureSqlQuery(self::QUERY_CODE, self::QUERY_NAME, self::QUERY_SQL);
            Cache::forever($key, true);
        }
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, hasMore: bool}
     */
    private function runQuery(SapServiceLayer $client, int $from): array
    {
        $result = $client->runSqlQuery(self::QUERY_CODE, ['lastTransSeq' => $from]);

        // Query terhapus di SAP: buat ulang lalu coba sekali lagi.
        if ($result === null) {
            Cache::forget($this->queryCacheKey());
            $this->ensureQuery($client, force: true);
            $result = $client->runSqlQuery(self::QUERY_CODE, ['lastTransSeq' => $from])
                ?? throw new RuntimeException('SQL Query '.self::QUERY_CODE.' tidak ditemukan di SAP B1.');
        }

        return $result;
    }

    private function queryCacheKey(): string
    {
        return 'sap-sql-query:'.$this->company->id.':'.md5(self::QUERY_SQL);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function missingMaster(array $row): ?string
    {
        $warehouseCode = (string) ($row['LocCode'] ?? '');
        $itemCode = (string) ($row['ItemCode'] ?? '');

        if ($this->warehouse($warehouseCode) === null) {
            return "warehouse {$warehouseCode} belum ada di easyPOS";
        }

        if ($this->productId($itemCode) === null) {
            return "item {$itemCode} belum ada di easyPOS";
        }

        return null;
    }

    /**
     * Mutasi dari A/R Invoice yang diposting easyPOS sudah tercatat saat penjualan terjadi:
     * baris OIVL cukup ditautkan ke baris buku besar penjualan itu (qty tidak dihitung dua kali).
     *
     * @param  array<string, mixed>  $row
     */
    private function linkToEasyPosSale(array $row): bool
    {
        if ((int) ($row['TransType'] ?? 0) !== SapSalePosting::OBJ_INVOICE || ! isset($row['CreatedBy'])) {
            return false;
        }

        $saleId = Sale::where('company_id', $this->company->id)
            ->where('sap_invoice_doc_entry', (int) $row['CreatedBy'])
            ->value('id');
        if ($saleId === null) {
            return false;
        }

        $qty = round((float) ($row['InQty'] ?? 0) - (float) ($row['OutQty'] ?? 0), 3);
        $ledger = WarehouseStockLedger::where('ref_type', 'sale')
            ->where('ref_id', $saleId)
            ->where('warehouse_id', $this->warehouse((string) $row['LocCode'])->id)
            ->where('product_id', $this->productId((string) $row['ItemCode']))
            ->whereNull('sap_trans_seq')
            ->where('qty', $qty)
            ->orderBy('created_at')
            ->first();
        if ($ledger === null) {
            return false;
        }

        $ledger->forceFill($this->sapAttributes($row))->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function sapAttributes(array $row): array
    {
        $docDate = (string) ($row['DocDate'] ?? '');

        return [
            'sap_trans_seq' => (int) $row['TransSeq'],
            'sap_trans_type' => (int) ($row['TransType'] ?? 0),
            'sap_doc_entry' => isset($row['CreatedBy']) ? (int) $row['CreatedBy'] : null,
            'sap_doc_num' => isset($row['BASE_REF']) ? mb_substr((string) $row['BASE_REF'], 0, 20) : null,
            'sap_doc_line' => isset($row['DocLineNum']) ? (int) $row['DocLineNum'] : null,
            'doc_date' => preg_match('/^\d{8}$/', $docDate) ? Carbon::createFromFormat('Ymd', $docDate)->toDateString() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function apply(array $row): bool
    {
        $qty = round((float) ($row['InQty'] ?? 0) - (float) ($row['OutQty'] ?? 0), 3);
        $transType = (int) ($row['TransType'] ?? 0);

        try {
            WarehouseStockService::record(
                $this->warehouse((string) $row['LocCode']),
                $this->productId((string) $row['ItemCode']),
                WarehouseStockLedger::typeForSap($transType, $qty),
                $qty,
                [
                    'created_by' => null,
                    'attributes' => $this->sapAttributes($row),
                ]
            );
        } catch (UniqueConstraintViolationException) {
            // Sudah tersalin oleh proses lain.
            return false;
        }

        return true;
    }

    private function warehouse(string $code): ?Warehouse
    {
        if ($code === '') {
            return null;
        }

        return $this->warehouses[$code] ??= Warehouse::where('company_id', $this->company->id)->where('code', $code)->first();
    }

    private function productId(string $sku): ?string
    {
        if ($sku === '') {
            return null;
        }

        return $this->products[$sku] ??= Product::where('company_id', $this->company->id)->where('sku', $sku)->value('id');
    }

    /**
     * Saldo lama (hasil timpa OITW) dibuang; saldo dibangun ulang dari seluruh riwayat mutasi.
     */
    private function resetBalances(): void
    {
        DB::transaction(function () {
            WarehouseStock::where('company_id', $this->company->id)->update(['on_hand' => 0]);
        });
    }
}
