<?php

namespace App\Support\Sap;

use App\Models\Brand;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SapSyncRun;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Impor master data SAP B1 (Service Layer) ke easyPOS.
 *
 * - Mode `initial`: easyPOS masih kosong, semua baris dari SAP langsung di-INSERT tanpa
 *   pencocokan. Bila impor awal pernah terhenti di tengah jalan, baris yang sudah ada
 *   (bentrok kode unik) dilewati sehingga impor bisa diulang.
 * - Mode `delta`: baris dicocokkan dengan kode SAP (`code` / `sku` per company) lalu
 *   dibuat atau diperbarui. Items (termasuk harga) & Customer hanya yang berubah sejak penanda
 *   waktu terakhir (UpdateDate + UpdateTime dari jam SAP sendiri). Saldo OITW hanya untuk item
 *   yang bermutasi sejak sinkronisasi sebelumnya; rekonsiliasi penuh sekali sehari. Master kecil
 *   tanpa penanda waktu di SAP diambil utuh, dan baris yang tidak berubah tidak ditulis ulang.
 */
class SapImportService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /**
     * Objek yang diimpor, berurutan: kunci ringkasan => [label, tabel SAP].
     */
    public const ENTITIES = [
        'tax_group' => ['Tax Group (Output Tax)', 'OVTG'],
        'warehouse' => ['Warehouse', 'OWHS'],
        'item_category' => ['Item Category', 'OITB'],
        'manufacturer' => ['Manufacturer', 'OMRC'],
        'unit' => ['Satuan (Unit of Measurement)', 'OUOM'],
        'customer_group' => ['Customer Group', 'OCRG'],
        'customer' => ['Customer', 'OCRD'],
        'item_master' => ['Produk (Item Master Data)', 'OITM'],
        'price_list' => ['Price List', 'OPLN'],
        'price_list_detail' => ['Price List Details', 'ITM1'],
        'stock_mutation' => ['Mutasi Stok', 'OIVL'],
        'warehouse_stock' => ['Saldo Stok SAP (rekonsiliasi)', 'OITW'],
    ];

    /** @var array<string, array<string, int|string>> */
    private array $summary = [];

    /** @var array<string, string> kode → id */
    private array $units = [];

    /** @var array<string, string> */
    private array $groups = [];

    /** @var array<string, string> */
    private array $brands = [];

    /** @var array<string, string> nomor pricelist SAP → id */
    private array $priceLists = [];

    /** @var array<string, string> */
    private array $customerGroups = [];

    /** @var array<string, string> sku → id */
    private array $products = [];

    /** @var array<string, string> barcode → sku (pencegah bentrok barcode pada impor awal) */
    private array $barcodes = [];

    /** @var array<string, string> telepon → kode pelanggan */
    private array $phones = [];

    /** @var array<string, string> objek → "Y-m-d H:i:s" perubahan terbaru yang terbaca (jam SAP) */
    private array $watermarks = [];

    /** Rekonsiliasi saldo OITW penuh paling lama selang waktu ini. */
    private const FULL_RECONCILE_HOURS = 24;

    /** Batas jumlah ItemCode per filter OData untuk rekonsiliasi item yang bermutasi. */
    private const ITEM_FILTER_CHUNK = 20;

    private string $companyId;

    public function __construct(private readonly CompanyDetail $company, private readonly SapSyncRun $run)
    {
        $this->companyId = $company->id;
    }

    public function run(): void
    {
        if ($this->isCancelled()) {
            return;
        }

        $this->run->update([
            'status' => SapSyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ]);

        $startedAt = now();
        $client = null;

        foreach (array_keys(self::ENTITIES) as $entity) {
            $this->summary[$entity] = $this->emptyProgress();
        }
        $this->run->update(['summary' => $this->summary]);

        try {
            $client = new SapServiceLayer($this->company);
            $client->login();

            $previousSyncAt = $this->company->sap_last_synced_at;
            $itemsChanged = $this->changedFilter('items');
            $customersChanged = $this->changedFilter('business_partners');

            // Urutan mengikuti ketergantungan data.
            $this->importTaxGroups($client);       // OVTG (Output Tax, aktif)
            $this->importWarehouses($client);       // OWHS (semua; U_SOL_RETAIL = Y → retail)
            $this->importItemGroups($client);       // OITB
            $this->importManufacturers($client);    // OMRC
            $this->importUnits($client);            // OUOM
            $this->importCustomerGroups($client);   // OCRG (CardType = C)
            $this->importCustomers($client, $customersChanged); // OCRD (CardType = C)
            $this->importItems($client, $itemsChanged);    // OITM
            $this->importPriceLists($client);       // OPLN
            $this->importPriceListDetails($client, $itemsChanged); // ITM1
            $this->importStockMutations($client);   // OIVL → buku besar & on_hand
            $fullReconcile = $this->importWarehouseStocks($client, $previousSyncAt);  // OITW → sap_on_hand (rekonsiliasi)

            $this->abortIfCancelled();

            $this->company->forceFill(array_filter([
                'sap_last_synced_at' => $startedAt,
                'sap_initial_imported_at' => $this->company->sap_initial_imported_at ?? $startedAt,
                'sap_sync_watermarks' => array_merge($this->company->sap_sync_watermarks ?? [], $this->watermarks),
                'sap_stock_reconciled_at' => $fullReconcile ? $startedAt : null,
            ]))->save();

            $this->run->update([
                'status' => SapSyncRun::STATUS_SUCCESS,
                'finished_at' => now(),
                'summary' => $this->summary,
            ]);
        } catch (SapImportCancelled) {
            // Status sudah diset oleh pembatalan; cukup simpan progres terakhir.
            $this->run->update(['summary' => $this->summary]);
        } catch (Throwable $e) {
            $this->run->update([
                'status' => SapSyncRun::STATUS_FAILED,
                'finished_at' => now(),
                'summary' => $this->summary,
                'error' => Str::limit($e->getMessage(), 500),
            ]);
        } finally {
            $client?->logout();
        }
    }

    private function isCancelled(): bool
    {
        return SapSyncRun::whereKey($this->run->id)->value('status') === SapSyncRun::STATUS_CANCELLED;
    }

    private function abortIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new SapImportCancelled('Impor dibatalkan.');
        }
    }

    private function isInitial(): bool
    {
        return $this->run->mode === SapSyncRun::MODE_INITIAL;
    }

    /**
     * Tax Group bertipe Output Tax yang aktif. Tarif = baris VatGroups_Lines terbaru yang sudah
     * berlaku. Selalu dicocokkan berdasarkan kode (juga saat impor awal) agar Tax Group manual
     * berkode sama diambil alih SAP dan menjadi hanya-baca.
     */
    private function importTaxGroups(SapServiceLayer $client): void
    {
        $today = now()->toDateString();

        $this->each('tax_group', $client, 'VatGroups', [], function (array $row) use ($today) {
            $code = $this->cut($row['Code'] ?? '', 30);
            if ($code === '' || ($row['Category'] ?? null) !== 'bovcOutputTax' || ($row['Inactive'] ?? 'tNO') === 'tYES') {
                return null;
            }

            $current = collect($row['VatGroups_Lines'] ?? [])
                ->map(fn (array $line) => ['from' => substr((string) ($line['Effectivefrom'] ?? ''), 0, 10), 'rate' => (float) ($line['Rate'] ?? 0)])
                ->filter(fn (array $line) => $line['from'] === '' || $line['from'] <= $today)
                ->sortBy('from')
                ->last();

            $taxGroup = TaxGroup::firstOrNew(['company_id' => $this->companyId, 'code' => $code]);
            $taxGroup->forceFill([
                'name' => $this->cut($row['Name'] ?? '', 255) ?: $code,
                'rate' => $current['rate'] ?? 0,
                'effective_from' => ($current['from'] ?? '') ?: null,
                'source' => TaxGroup::SOURCE_SAP,
                'is_active' => true,
            ]);

            $outcome = $this->persist($taxGroup);
            CompanyDetail::where('tax_group_id', $taxGroup->id)->update(['ppn_rate' => $taxGroup->rate]);

            return $outcome;
        });
    }

    /**
     * Semua warehouse diimpor; UDF U_SOL_RETAIL = 'Y' menandai warehouse retail (yang dapat dipakai gerai).
     * Profit center dari UDF U_SOL_PRFC (boleh kosong).
     */
    private function importWarehouses(SapServiceLayer $client): void
    {
        // Service Layer menolak UDF pada $filter/$select untuk Warehouses, jadi dibaca dari baris penuh.
        $this->each('warehouse', $client, 'Warehouses', [], function (array $row) {
            $code = $this->cut($row['WarehouseCode'] ?? '', 30);
            if ($code === '') {
                return null;
            }

            $profitCenter = $this->cut($row['U_SOL_PRFC'] ?? '', 50);
            $cashAccount = $this->cut($row['U_SOL_CASH_ACC'] ?? '', 25);

            [, $outcome] = $this->store(Warehouse::class, ['code' => $code], [
                'name' => $this->cut($row['WarehouseName'] ?? '', 255) ?: $code,
                'profit_center' => $profitCenter !== '' ? $profitCenter : null,
                'cash_account' => $cashAccount !== '' ? $cashAccount : null,
                'is_retail' => ($row['U_SOL_RETAIL'] ?? null) === 'Y',
                'is_active' => ($row['Inactive'] ?? 'tNO') !== 'tYES',
            ]);

            return $outcome;
        });
    }

    private function importItemGroups(SapServiceLayer $client): void
    {
        $this->each('item_category', $client, 'ItemGroups', ['$select' => 'Number,GroupName'], function (array $row) {
            $code = $this->cut($row['Number'] ?? '', 30);
            if ($code === '') {
                return null;
            }

            [$model, $outcome] = $this->store(ProductGroup::class, ['code' => $code], ['name' => $row['GroupName'] ?: $code]);
            if ($model) {
                $this->groups[$code] = $model->id;
            }

            return $outcome;
        });
    }

    private function importManufacturers(SapServiceLayer $client): void
    {
        $this->each('manufacturer', $client, 'Manufacturers', ['$select' => 'Code,ManufacturerName'], function (array $row) {
            $code = $this->cut($row['Code'] ?? '', 30);
            if ($code === '') {
                return null;
            }

            [$model, $outcome] = $this->store(Brand::class, ['code' => $code], ['name' => $row['ManufacturerName'] ?: $code]);
            if ($model) {
                $this->brands[$code] = $model->id;
            }

            return $outcome;
        });
    }

    private function importUnits(SapServiceLayer $client): void
    {
        $this->each('unit', $client, 'UnitOfMeasurements', ['$select' => 'Code,Name'], function (array $row) {
            $code = $this->cut($row['Code'] ?? '', 20);
            if ($code === '') {
                return null;
            }

            [$model, $outcome] = $this->store(Unit::class, ['code' => $code], ['name' => $row['Name'] ?: $code]);
            if ($model) {
                $this->units[$code] = $model->id;
            }

            return $outcome;
        });
    }

    private function importCustomerGroups(SapServiceLayer $client): void
    {
        $query = ['$select' => 'Code,Name', '$filter' => "Type eq 'bbpgt_CustomerGroup'"];

        $this->each('customer_group', $client, 'BusinessPartnerGroups', $query, function (array $row) {
            $code = $this->cut($row['Code'] ?? '', 30);
            if ($code === '') {
                return null;
            }

            [$model, $outcome] = $this->store(CustomerGroup::class, ['code' => $code], [
                'name' => $row['Name'] ?: $code,
                'source' => CustomerGroup::SOURCE_SAP,
            ]);
            if ($model) {
                $this->customerGroups[$code] = $model->id;
            }

            return $outcome;
        });
    }

    private function importCustomers(SapServiceLayer $client, ?string $changed): void
    {
        $filter = "CardType eq 'cCustomer'";
        if ($changed !== null) {
            $filter .= " and ({$changed})";
        }

        $query = [
            '$select' => 'CardCode,CardName,GroupCode,Phone1,Cellular,EmailAddress,Address,Valid,Frozen,UpdateDate,UpdateTime',
            '$filter' => $filter,
        ];

        $this->each('customer', $client, 'BusinessPartners', $query, function (array $row) {
            $this->trackWatermark('business_partners', $row);
            $code = $this->cut($row['CardCode'] ?? '', 30);
            if ($code === '') {
                return null;
            }

            // Customer yang dibuat di easyPOS lalu dikirim ke SAP tetap dikelola dari easyPOS.
            if (! $this->isInitial() && Customer::where('company_id', $this->companyId)->where('code', $code)->where('source', Customer::SOURCE_MANUAL)->exists()) {
                return 'skipped';
            }

            $attrs = [
                'source' => Customer::SOURCE_SAP,
                'name' => $row['CardName'] ?: $code,
                'email' => $this->cut($row['EmailAddress'] ?? '', 150) ?: null,
                'address' => ($row['Address'] ?? null) ?: null,
                'is_active' => $this->isActive($row),
            ];

            $groupId = $this->customerGroups[(string) ($row['GroupCode'] ?? '')] ?? null;
            if ($groupId) {
                $attrs['customer_group_id'] = $groupId;
            }

            $phone = $this->cut($row['Cellular'] ?? $row['Phone1'] ?? '', 30);
            if ($this->phoneAvailable($phone, $code)) {
                $attrs['phone'] = $phone;
            }

            return $this->store(Customer::class, ['code' => $code], $attrs)[1];
        });
    }

    private function importItems(SapServiceLayer $client, ?string $changed): void
    {
        $query = [
            '$select' => 'ItemCode,ItemName,ItemsGroupCode,Manufacturer,InventoryUOM,SalesUnit,BarCode,Valid,Frozen,InventoryItem,UpdateDate,UpdateTime',
        ];
        if ($changed !== null) {
            $query['$filter'] = $changed;
        }

        $this->each('item_master', $client, 'Items', $query, function (array $row) {
            $this->trackWatermark('items', $row);
            $sku = $this->cut($row['ItemCode'] ?? '', 40);
            if ($sku === '') {
                return null;
            }

            $brandCode = (int) ($row['Manufacturer'] ?? -1);
            $unitCode = $this->cut($row['SalesUnit'] ?? $row['InventoryUOM'] ?? '', 20) ?: 'PCS';

            $attrs = [
                'product_group_id' => $this->groups[(string) ($row['ItemsGroupCode'] ?? '')] ?? $this->fallbackGroupId(),
                'unit_id' => $this->unitId($unitCode),
                'brand_id' => $brandCode > 0 ? ($this->brands[(string) $brandCode] ?? null) : null,
                'name' => $row['ItemName'] ?: $sku,
                'track_stock' => ($row['InventoryItem'] ?? 'tYES') === 'tYES',
                'is_active' => $this->isActive($row),
            ];

            $barcode = $this->cut($row['BarCode'] ?? '', 60);
            if ($this->barcodeAvailable($barcode, $sku)) {
                $attrs['barcode'] = $barcode;
            }

            [$model, $outcome] = $this->store(Product::class, ['sku' => $sku], $attrs);
            if ($model) {
                $this->products[$sku] = $model->id;
            }

            return $outcome;
        });
    }

    private function importPriceLists(SapServiceLayer $client): void
    {
        // Hanya price list retail (UDF U_SOL_RETAIL = 'Y'); disaring di sini seperti Warehouses.
        $this->each('price_list', $client, 'PriceLists', [], function (array $row) {
            $code = $this->cut($row['PriceListNo'] ?? '', 30);
            if ($code === '' || ($row['U_SOL_RETAIL'] ?? null) !== 'Y') {
                return null;
            }

            [$model, $outcome] = $this->store(PriceList::class, ['code' => $code], [
                'name' => $row['PriceListName'] ?: $code,
                'is_active' => ($row['Active'] ?? 'tYES') === 'tYES',
            ]);
            if ($model) {
                $this->priceLists[$code] = $model->id;
            }

            return $outcome;
        });
    }

    /**
     * Harga per daftar harga (ITM1). Harga dasar produk diisi dari daftar harga
     * dengan nomor terkecil yang berharga lebih dari 0.
     */
    private function importPriceListDetails(SapServiceLayer $client, ?string $changed): void
    {
        $query = ['$select' => 'ItemCode,ItemPrices'];
        if ($changed !== null) {
            $query['$filter'] = $changed;
        }

        $this->each('price_list_detail', $client, 'Items', $query, function (array $row) {
            $sku = $this->cut($row['ItemCode'] ?? '', 40);
            $productId = $sku === '' ? null : ($this->products[$sku]
                ?? Product::where('company_id', $this->companyId)->where('sku', $sku)->value('id'));
            if (! $productId) {
                return null;
            }

            // Hanya harga pada price list retail yang diimpor.
            $prices = array_intersect_key($this->extractPrices($row['ItemPrices'] ?? []), $this->priceLists);
            if ($prices === []) {
                return 'unchanged';
            }

            $outcomes = [];
            foreach ($prices as $listNo => $price) {
                $priceListId = $this->priceLists[(string) $listNo] ?? null;
                if ($priceListId === null) {
                    continue;
                }

                $outcomes[] = $this->storePriceListItem($priceListId, $productId, $price);
            }

            $basePrice = $prices[array_key_first($prices)];
            $baseChanged = Product::whereKey($productId)->where('base_price', '!=', $basePrice)->update(['base_price' => $basePrice]) > 0;

            if (in_array('created', $outcomes, true)) {
                return 'created';
            }

            return in_array('updated', $outcomes, true) || $baseChanged ? 'updated' : 'unchanged';
        });
    }

    /**
     * Saldo inventory (OITW) hanya untuk warehouse retail di easyPOS dan produk yang sudah
     * terimpor. Selalu diambil penuh: perubahan saldo tidak mengubah UpdateDate item di SAP.
     */
    /**
     * Salin mutasi stok OIVL. Bila tertahan (master belum ada, atau query SAP timeout), objek
     * ditandai gagal tanpa menggagalkan run; sinkronisasi mutasi per menit melanjutkannya.
     */
    private function importStockMutations(SapServiceLayer $client): void
    {
        $this->abortIfCancelled();

        $entity = 'stock_mutation';
        $this->summary[$entity] = ['status' => self::STATUS_RUNNING] + $this->emptyProgress();
        $this->run->update(['summary' => $this->summary]);

        try {
            $result = (new SapStockMutationService($this->company))->sync($client, wait: true, lookback: true);
        } catch (Throwable $e) {
            // Query OIVL bisa tertahan sementara di SAP: jangan gagalkan sinkronisasi master;
            // sinkronisasi mutasi per menit melanjutkannya. Sesi SL yang tertahan diganti baru.
            $this->summary[$entity]['status'] = self::STATUS_FAILED;
            $this->run->update(['summary' => $this->summary]);
            $this->company->forceFill(['sap_stock_sync_error' => Str::limit($e->getMessage(), 500)])->save();
            $client->renewSession();

            return;
        }

        $this->summary[$entity] = [
            'status' => $result['busy'] || $result['halted'] ? self::STATUS_FAILED : self::STATUS_DONE,
            'fetched' => $result['fetched'],
            'created' => $result['created'],
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => $result['skipped'],
        ];
        $this->run->update(['summary' => $this->summary]);
    }

    /**
     * Saldo OITW semua warehouse disimpan sebagai sap_on_hand untuk rekonsiliasi; on_hand
     * easyPOS berasal dari buku besar mutasi dan tidak ditimpa. Delta hanya membaca item yang
     * bermutasi sesudah saldo SAP-nya terakhir dicek (dilewati bila tidak ada); rekonsiliasi penuh saat
     * impor awal dan bila yang terakhir lebih lama dari FULL_RECONCILE_HOURS.
     *
     * @return bool true bila rekonsiliasi penuh dijalankan
     */
    private function importWarehouseStocks(SapServiceLayer $client, ?Carbon $previousSyncAt): bool
    {
        $warehouses = Warehouse::where('company_id', $this->companyId)->pluck('id', 'code')->all();
        $checkedAt = now();
        $select = ['$select' => 'ItemCode,ItemWarehouseInfoCollection'];

        $full = $this->isInitial() || $previousSyncAt === null
            || $this->company->sap_stock_reconciled_at === null
            || $this->company->sap_stock_reconciled_at->lt(now()->subHours(self::FULL_RECONCILE_HOURS));

        if ($full) {
            $queries = [$select];
        } else {
            $queries = WarehouseStockLedger::query()
                ->join('products', 'products.id', '=', 'warehouse_stock_ledgers.product_id')
                ->leftJoin('warehouse_stocks', fn ($join) => $join
                    ->on('warehouse_stocks.warehouse_id', '=', 'warehouse_stock_ledgers.warehouse_id')
                    ->on('warehouse_stocks.product_id', '=', 'warehouse_stock_ledgers.product_id'))
                ->where('warehouse_stock_ledgers.company_id', $this->companyId)
                // Bermutasi sesudah saldo SAP-nya terakhir dicek.
                ->where(fn ($q) => $q->whereNull('warehouse_stocks.sap_checked_at')
                    ->orWhereColumn('warehouse_stock_ledgers.created_at', '>', 'warehouse_stocks.sap_checked_at'))
                ->distinct()
                ->pluck('products.sku')
                ->chunk(self::ITEM_FILTER_CHUNK)
                ->map(fn ($skus) => $select + ['$filter' => $skus->map(fn ($sku) => "ItemCode eq '".str_replace("'", "''", $sku)."'")->implode(' or ')])
                ->values()
                ->all();

            // Tidak ada item yang bermutasi: tidak ada yang perlu dicek ke SAP.
            if ($queries === []) {
                $this->summary['warehouse_stock'] = ['status' => self::STATUS_DONE] + array_diff_key($this->emptyProgress(), ['status' => true]);
                $this->run->update(['summary' => $this->summary]);

                return false;
            }
        }

        $this->each('warehouse_stock', $client, 'Items', $queries, function (array $row) use ($warehouses, $checkedAt) {
            $sku = $this->cut($row['ItemCode'] ?? '', 40);
            $productId = $sku === '' ? null : ($this->products[$sku]
                ?? Product::where('company_id', $this->companyId)->where('sku', $sku)->value('id'));
            if (! $productId || $warehouses === []) {
                return null;
            }

            $outcomes = SapStockMutationService::storeSapBalances($this->companyId, $productId, $warehouses, $row['ItemWarehouseInfoCollection'] ?? [], $checkedAt);

            if ($outcomes === []) {
                return null;
            }

            foreach (['created', 'updated', 'unchanged'] as $outcome) {
                if (in_array($outcome, $outcomes, true)) {
                    return $outcome;
                }
            }

            return 'skipped';
        });

        return $full;
    }

    private function storePriceListItem(string $priceListId, string $productId, float $price): string
    {
        $keys = ['price_list_id' => $priceListId, 'product_id' => $productId, 'min_qty' => 1];

        if ($this->isInitial()) {
            try {
                PriceListItem::create($keys + ['price' => $price]);

                return 'created';
            } catch (UniqueConstraintViolationException) {
                return 'skipped';
            }
        }

        $item = PriceListItem::firstOrNew($keys);
        $item->price = $price;

        return $this->persist($item);
    }

    /**
     * Simpan satu baris master company-level.
     * Impor awal: INSERT langsung. Selain itu: cocokkan lalu buat/perbarui.
     *
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $keys  kunci pencocokan (selain company_id)
     * @param  array<string, mixed>  $attrs
     * @return array{0: ?Model, 1: string}
     */
    private function store(string $class, array $keys, array $attrs): array
    {
        $keys = ['company_id' => $this->companyId] + $keys;

        if ($this->isInitial()) {
            try {
                $model = new $class;
                $model->forceFill($keys + $attrs)->save();

                return [$model, 'created'];
            } catch (UniqueConstraintViolationException) {
                // Impor awal sebelumnya terhenti di tengah jalan: baris ini sudah ada.
                return [$class::query()->where($keys)->first(), 'skipped'];
            }
        }

        $model = $class::firstOrNew($keys);
        $model->forceFill($attrs);

        return [$model, $this->persist($model)];
    }

    /**
     * Iterasi sebuah resource dan hitung hasilnya per objek.
     *
     * @param  array<string, string>|list<array<string, string>>  $query  satu query OData, atau daftar query
     * @param  callable(array<string, mixed>): ?string  $handler  created|updated|unchanged|skipped, atau null bila dilewati
     */
    private function each(string $entity, SapServiceLayer $client, string $resource, array $query, callable $handler): void
    {
        $this->abortIfCancelled();

        $this->summary[$entity] = ['status' => self::STATUS_RUNNING] + $this->emptyProgress();
        $this->run->update(['summary' => $this->summary]);

        $queries = $query !== [] && array_is_list($query) ? $query : [$query];

        try {
            foreach ($queries as $resourceQuery) {
                foreach ($client->each($resource, $resourceQuery) as $row) {
                    $this->summary[$entity]['fetched']++;

                    try {
                        $outcome = $handler($row) ?? 'skipped';
                    } catch (Throwable) {
                        $outcome = 'skipped';
                    }

                    $this->summary[$entity][$outcome]++;

                    // Simpan progres berkala agar UI bisa menampilkan jumlah yang sedang berjalan.
                    if ($this->summary[$entity]['fetched'] % 100 === 0) {
                        $this->run->update(['summary' => $this->summary]);
                        $this->abortIfCancelled();
                    }
                }
            }
        } catch (SapImportCancelled $e) {
            $this->summary[$entity]['status'] = self::STATUS_PENDING;

            throw $e;
        } catch (Throwable $e) {
            $this->summary[$entity]['status'] = self::STATUS_FAILED;
            $this->run->update(['summary' => $this->summary]);

            throw $e;
        }

        $this->summary[$entity]['status'] = self::STATUS_DONE;
        $this->run->update(['summary' => $this->summary]);
    }

    /**
     * Filter OData "berubah sejak penanda waktu terakhir" untuk objek yang punya UpdateDate +
     * UpdateTime (Items, BusinessPartners). Batas `ge` membaca ulang baris di detik yang sama
     * agar tidak ada yang terlewat. Null pada impor awal (ambil semua).
     */
    private function changedFilter(string $object): ?string
    {
        if ($this->isInitial()) {
            return null;
        }

        $watermark = $this->company->sap_sync_watermarks[$object] ?? null;
        if ($watermark === null) {
            // Belum ada penanda (data impor lama): perilaku sebelumnya, berubah sejak sehari sebelum sinkronisasi terakhir.
            return $this->company->sap_last_synced_at
                ? "UpdateDate ge '".Carbon::parse($this->company->sap_last_synced_at)->subDay()->toDateString()."'"
                : null;
        }

        [$date, $time] = explode(' ', $watermark);

        return "UpdateDate gt '{$date}' or (UpdateDate eq '{$date}' and UpdateTime ge '{$time}')";
    }

    /**
     * Catat waktu perubahan terbaru yang terbaca dari SAP (jam SAP, bukan jam easyPOS).
     *
     * @param  array<string, mixed>  $row
     */
    private function trackWatermark(string $object, array $row): void
    {
        $date = substr((string) ($row['UpdateDate'] ?? ''), 0, 10);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }

        $time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) ($row['UpdateTime'] ?? '')) ? (string) $row['UpdateTime'] : '00:00:00';
        $stamp = $date.' '.(strlen($time) === 5 ? $time.':00' : $time);

        if ($stamp > ($this->watermarks[$object] ?? '')) {
            $this->watermarks[$object] = $stamp;
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function emptyProgress(): array
    {
        return [
            'status' => self::STATUS_PENDING,
            'fetched' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0,
        ];
    }

    private function persist(Model $model): string
    {
        if (! $model->exists) {
            $model->save();

            return 'created';
        }

        if ($model->isDirty()) {
            $model->save();

            return 'updated';
        }

        return 'unchanged';
    }

    private function barcodeAvailable(string $barcode, string $sku): bool
    {
        if ($barcode === '') {
            return false;
        }

        if (isset($this->barcodes[$barcode]) && $this->barcodes[$barcode] !== $sku) {
            return false;
        }

        $taken = ! $this->isInitial() && Product::where('company_id', $this->companyId)
            ->where('barcode', $barcode)->where('sku', '!=', $sku)->exists();

        if (! $taken) {
            $this->barcodes[$barcode] = $sku;
        }

        return ! $taken;
    }

    private function phoneAvailable(string $phone, string $code): bool
    {
        if ($phone === '') {
            return false;
        }

        if (isset($this->phones[$phone]) && $this->phones[$phone] !== $code) {
            return false;
        }

        $taken = ! $this->isInitial() && Customer::where('company_id', $this->companyId)
            ->where('phone', $phone)->where('code', '!=', $code)->exists();

        if (! $taken) {
            $this->phones[$phone] = $code;
        }

        return ! $taken;
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemPrices
     * @return array<string, float> nomor pricelist → harga (harga > 0, urut nomor pricelist)
     */
    private function extractPrices(array $itemPrices): array
    {
        $prices = [];
        foreach ($itemPrices as $row) {
            $price = (float) ($row['Price'] ?? 0);
            if ($price > 0) {
                $prices[(string) $row['PriceList']] = $price;
            }
        }
        ksort($prices, SORT_NUMERIC);

        return $prices;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isActive(array $row): bool
    {
        return ($row['Valid'] ?? 'tYES') === 'tYES' && ($row['Frozen'] ?? 'tNO') !== 'tYES';
    }

    private function unitId(string $code): string
    {
        return $this->units[$code] ??= Unit::firstOrCreate(
            ['company_id' => $this->companyId, 'code' => $code],
            ['name' => $code]
        )->id;
    }

    private function fallbackGroupId(): string
    {
        return $this->groups['SAP-DEFAULT'] ??= ProductGroup::firstOrCreate(
            ['company_id' => $this->companyId, 'code' => 'SAP-DEFAULT'],
            ['name' => 'Tanpa Kategori (SAP)']
        )->id;
    }

    private function cut(mixed $value, int $max): string
    {
        return Str::limit(trim((string) $value), $max, '');
    }
}
