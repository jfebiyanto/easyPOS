<?php

namespace App\Support\Sap;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferReturn;
use App\Models\StockTransferShipment;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\StockTransferService;
use App\Support\WarehouseStockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

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
 * - Setelah menyalin mutasi, saldo SAP (OITW) item yang bermutasi saja langsung dibaca ulang
 *   agar kolom Saldo SAP tidak menunggu sinkronisasi master (selisih semu).
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

    /** @var array<string, true> sku yang bermutasi pada sinkronisasi ini */
    private array $touchedSkus = [];

    /** Batas jumlah ItemCode per filter OData saat membaca ulang saldo SAP. */
    private const ITEM_FILTER_CHUNK = 20;

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
            $this->refreshSapBalances($client);

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

                $linked = $this->linkToEasyPosSale($row) || $this->linkToEasyPosTransfer($row);
                $result[$linked || ! $this->apply($row) ? 'skipped' : 'created']++;
                $known[$seq] = true;
                $this->touchedSkus[(string) $row['ItemCode']] = true;
            }

            if (! $hasMore || $rows === []) {
                break;
            }

            $from = (int) end($rows)['TransSeq'];
        }

        return $result;
    }

    /**
     * Simpan saldo OITW (ItemWarehouseInfoCollection) satu item sebagai sap_on_hand per warehouse.
     * on_hand tidak disentuh: saldo easyPOS berasal dari buku besar mutasi.
     *
     * @param  array<string, string>  $warehouses  kode → id
     * @param  array<int, array<string, mixed>>  $infos
     * @return list<string> created|updated|unchanged per warehouse
     */
    public static function storeSapBalances(string $companyId, string $productId, array $warehouses, array $infos, Carbon $checkedAt): array
    {
        $outcomes = [];

        foreach ($infos as $info) {
            $warehouseId = $warehouses[(string) ($info['WarehouseCode'] ?? '')] ?? null;
            if ($warehouseId === null) {
                continue;
            }

            // Selalu dicocokkan (juga saat impor awal): baris bisa sudah dibuat oleh mutasi stok.
            $stock = WarehouseStock::firstOrNew(['company_id' => $companyId, 'warehouse_id' => $warehouseId, 'product_id' => $productId]);
            $stock->forceFill([
                'committed' => (float) ($info['Committed'] ?? 0),
                'on_order' => (float) ($info['Ordered'] ?? 0),
                'sap_on_hand' => (float) ($info['InStock'] ?? 0),
            ]);
            $outcomes[] = ! $stock->exists ? 'created' : ($stock->isDirty() ? 'updated' : 'unchanged');
            $stock->forceFill(['sap_checked_at' => $checkedAt])->save();
        }

        return $outcomes;
    }

    /**
     * Baca ulang saldo SAP item yang bermutasi. Kegagalan diabaikan: sinkronisasi master
     * merekonsiliasi item yang bermutasi sesudah saldo SAP-nya terakhir dicek.
     */
    private function refreshSapBalances(SapServiceLayer $client): void
    {
        if ($this->touchedSkus === []) {
            return;
        }

        $warehouses = Warehouse::where('company_id', $this->company->id)->pluck('id', 'code')->all();
        $checkedAt = now();

        try {
            foreach (array_chunk(array_keys($this->touchedSkus), self::ITEM_FILTER_CHUNK) as $skus) {
                $filter = collect($skus)->map(fn ($sku) => "ItemCode eq '".str_replace("'", "''", (string) $sku)."'")->implode(' or ');

                foreach ($client->each('Items', ['$select' => 'ItemCode,ItemWarehouseInfoCollection', '$filter' => $filter]) as $row) {
                    $productId = $this->productId((string) ($row['ItemCode'] ?? ''));
                    if ($productId !== null) {
                        self::storeSapBalances($this->company->id, $productId, $warehouses, $row['ItemWarehouseInfoCollection'] ?? [], $checkedAt);
                    }
                }
            }
        } catch (Throwable) {
            // Dicoba lagi oleh rekonsiliasi pada sinkronisasi master.
        }
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

        return $this->linkToLedger($row, 'sale', $saleId);
    }

    /**
     * Mutasi dari Stock Transfer yang diposting easyPOS (Shipment/Receipt/Return) sudah tercatat saat dokumen dibuat:
     * kedua baris OIVL (keluar dan masuk) ditautkan ke baris buku besar lawannya. Stock Transfer yang
     * dibuat langsung di SAP tidak punya dokumen easyPOS dan disalin seperti biasa.
     *
     * @param  array<string, mixed>  $row
     */
    private function linkToEasyPosTransfer(array $row): bool
    {
        if ((int) ($row['TransType'] ?? 0) !== SapStockTransferPosting::OBJ_STOCK_TRANSFER || ! isset($row['CreatedBy'])) {
            return false;
        }

        $documents = [
            StockTransferService::REF_SHIPMENT => StockTransferShipment::class,
            StockTransferService::REF_RECEIPT => StockTransferReceipt::class,
            StockTransferService::REF_RETURN => StockTransferReturn::class,
        ];

        foreach ($documents as $refType => $documentClass) {
            $documentId = $documentClass::where('company_id', $this->company->id)->where('sap_doc_entry', (int) $row['CreatedBy'])->value('id');

            if ($documentId !== null) {
                return $this->linkToLedger($row, $refType, $documentId);
            }
        }

        return false;
    }

    /**
     * Isi kolom sap_* pada satu baris buku besar dokumen easyPOS yang cocok (warehouse, produk, qty) dan belum tertaut.
     *
     * @param  array<string, mixed>  $row
     */
    private function linkToLedger(array $row, string $refType, string $refId): bool
    {
        $qty = round((float) ($row['InQty'] ?? 0) - (float) ($row['OutQty'] ?? 0), 3);
        $ledger = WarehouseStockLedger::where('ref_type', $refType)
            ->where('ref_id', $refId)
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
