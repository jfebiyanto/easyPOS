<?php

namespace App\Support\Sap;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\WarehouseStockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Salin mutasi stok SAP B1 (log OIVL) ke buku besar warehouse easyPOS, baris per baris,
 * sehingga riwayat dan saldo on_hand identik dengan SAP.
 *
 * - Kunci unik baris = TransSeq; cursor = TransSeq terbesar yang sudah tersalin.
 * - Setiap sinkronisasi membaca ulang LOOKBACK TransSeq terakhir agar baris yang ter-commit
 *   tidak berurutan di SAP tidak terlewat; baris yang sudah ada dilewati.
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

    /** Batas halaman per sinkronisasi; sisanya dilanjutkan pada sinkronisasi berikutnya. */
    private const MAX_PAGES = 50;

    /** @var array<string, Warehouse> kode → warehouse */
    private array $warehouses = [];

    /** @var array<string, string> sku → id */
    private array $products = [];

    public function __construct(private readonly CompanyDetail $company) {}

    /**
     * @param  bool  $wait  tunggu sinkronisasi lain selesai (impor master) alih-alih langsung berhenti
     * @return array{busy: bool, fetched: int, created: int, skipped: int, halted: ?string}
     */
    public function sync(SapServiceLayer $client, bool $wait = false): array
    {
        $result = ['busy' => false, 'fetched' => 0, 'created' => 0, 'skipped' => 0, 'halted' => null];
        $lock = Cache::lock('sap-stock-mutation:'.$this->company->id, 600);

        if (! ($wait ? $lock->block(120) : $lock->get())) {
            return ['busy' => true] + $result;
        }

        try {
            $client->ensureSqlQuery(self::QUERY_CODE, self::QUERY_NAME, self::QUERY_SQL);

            $hasSapRows = WarehouseStockLedger::where('company_id', $this->company->id)->whereNotNull('sap_trans_seq')->exists();
            if ($this->company->sap_stock_synced_at === null && ! $hasSapRows) {
                $this->resetBalances();
            }

            $result = $this->copyMutations($client, $result);

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
    private function copyMutations(SapServiceLayer $client, array $result): array
    {
        $cursor = (int) WarehouseStockLedger::where('company_id', $this->company->id)->max('sap_trans_seq');
        $from = max(0, $cursor - self::LOOKBACK);

        $known = WarehouseStockLedger::where('company_id', $this->company->id)
            ->where('sap_trans_seq', '>', $from)
            ->pluck('sap_trans_seq')
            ->flip()
            ->all();

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            ['rows' => $rows, 'hasMore' => $hasMore] = $client->runSqlQuery(self::QUERY_CODE, ['lastTransSeq' => $from]);

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

                $result[$this->apply($row) ? 'created' : 'skipped']++;
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
     * @param  array<string, mixed>  $row
     */
    private function apply(array $row): bool
    {
        $qty = round((float) ($row['InQty'] ?? 0) - (float) ($row['OutQty'] ?? 0), 3);
        $transType = (int) ($row['TransType'] ?? 0);
        $docDate = (string) ($row['DocDate'] ?? '');

        try {
            WarehouseStockService::record(
                $this->warehouse((string) $row['LocCode']),
                $this->productId((string) $row['ItemCode']),
                WarehouseStockLedger::typeForSap($transType, $qty),
                $qty,
                [
                    'created_by' => null,
                    'attributes' => [
                        'sap_trans_seq' => (int) $row['TransSeq'],
                        'sap_trans_type' => $transType,
                        'sap_doc_entry' => isset($row['CreatedBy']) ? (int) $row['CreatedBy'] : null,
                        'sap_doc_num' => isset($row['BASE_REF']) ? mb_substr((string) $row['BASE_REF'], 0, 20) : null,
                        'sap_doc_line' => isset($row['DocLineNum']) ? (int) $row['DocLineNum'] : null,
                        'doc_date' => preg_match('/^\d{8}$/', $docDate) ? Carbon::createFromFormat('Ymd', $docDate)->toDateString() : null,
                    ],
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
