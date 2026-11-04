<?php

namespace Tests\Feature;

use App\Jobs\RunSapImport;
use App\Models\Brand;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SapSyncRun;
use App\Models\Store;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\Sap\SapStockMutationService;
use App\Support\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SapImportTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co',
            'sap_enabled' => true,
            'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
            'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager',
            'sap_password' => 'secret',
        ]);

        $store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'STR001',
            'name' => 'Store Satu',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);

        $this->owner = User::create([
            'company_id' => $this->company->id,
            'store_id' => $store->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    private function fakeSap(string $itemName = 'Kopi Susu', bool $mutationQueryFails = false): void
    {
        // Reset stub sebelumnya: Http::fake() bersifat menambah dan stub pertama yang cocok menang.
        Http::swap(new HttpFactory);

        Http::fake([
            '*/Login' => Http::response(['SessionId' => 'abc'], 200),
            '*/Logout' => Http::response('', 204),
            '*/VatGroups*' => Http::response(['value' => [
                ['Code' => 'PPNK-11', 'Name' => 'PPN Keluaran 11%', 'Category' => 'bovcOutputTax', 'Inactive' => 'tNO', 'VatGroups_Lines' => [
                    ['Effectivefrom' => '2020-01-01T00:00:00Z', 'Rate' => 10],
                    ['Effectivefrom' => '2025-01-01T00:00:00Z', 'Rate' => 11],
                    ['Effectivefrom' => '2099-01-01T00:00:00Z', 'Rate' => 15],
                ]],
                ['Code' => 'PPNK-0', 'Name' => 'PPN Keluaran 0%', 'Category' => 'bovcOutputTax', 'Inactive' => 'tNO', 'VatGroups_Lines' => [['Effectivefrom' => '2025-01-01T00:00:00Z', 'Rate' => 0]]],
                ['Code' => 'PPNM-11', 'Name' => 'PPN Masukan 11%', 'Category' => 'bovcInputTax', 'Inactive' => 'tNO', 'VatGroups_Lines' => [['Effectivefrom' => '2025-01-01T00:00:00Z', 'Rate' => 11]]],
                ['Code' => 'S1', 'Name' => 'AU Sales', 'Category' => 'bovcOutputTax', 'Inactive' => 'tYES', 'VatGroups_Lines' => [['Effectivefrom' => '2000-01-01T00:00:00Z', 'Rate' => 10]]],
            ]]),
            '*/Warehouses*' => Http::response(['value' => [
                ['WarehouseCode' => 'WH-01', 'WarehouseName' => 'Gudang Retail 1', 'Inactive' => 'tNO', 'U_SOL_RETAIL' => 'Y', 'U_SOL_PRFC' => 'PC-JKT', 'U_SOL_CASH_ACC' => '1100-1001-0002'],
                ['WarehouseCode' => 'WH-02', 'WarehouseName' => 'Gudang Retail 2', 'Inactive' => 'tYES', 'U_SOL_RETAIL' => 'Y', 'U_SOL_PRFC' => ''],
                ['WarehouseCode' => 'GL99', 'WarehouseName' => 'Gudang Load', 'Inactive' => 'tNO', 'U_SOL_RETAIL' => 'N', 'U_SOL_PRFC' => null],
            ]]),
            "*/SQLQueries('EPOS_STOCK_MUTATION')/List" => $mutationQueryFails ? Http::response(['error' => ['message' => ['value' => 'timeout']]], 504) : Http::response(['value' => [
                ['TransSeq' => 1, 'TransType' => 20, 'CreatedBy' => 1, 'BASE_REF' => '1', 'DocLineNum' => 0, 'ItemCode' => 'ITM-001', 'LocCode' => 'WH-01', 'InQty' => 25, 'OutQty' => 0, 'DocDate' => '20261101'],
            ]]),
            '*/SQLQueries*' => Http::response(['SqlText' => SapStockMutationService::QUERY_SQL]),
            '*/UnitOfMeasurements*' => Http::response(['value' => [['Code' => 'PCS', 'Name' => 'Pieces']]]),
            '*/ItemGroups*' => Http::response(['value' => [['Number' => 100, 'GroupName' => 'Minuman']]]),
            '*/Manufacturers*' => Http::response(['value' => [['Code' => 5, 'ManufacturerName' => 'Indo Coffee']]]),
            '*/PriceLists*' => Http::response(['value' => [
                ['PriceListNo' => 1, 'PriceListName' => 'Harga Dasar', 'Active' => 'tYES', 'U_SOL_RETAIL' => 'Y'],
                ['PriceListNo' => 2, 'PriceListName' => 'Harga Member', 'Active' => 'tYES', 'U_SOL_RETAIL' => 'Y'],
                ['PriceListNo' => 3, 'PriceListName' => 'Harga Non Retail', 'Active' => 'tYES', 'U_SOL_RETAIL' => 'N'],
            ]]),
            '*/Items*' => Http::response(['value' => [[
                'ItemCode' => 'ITM-001',
                'ItemName' => $itemName,
                'ItemsGroupCode' => 100,
                'Manufacturer' => 5,
                'InventoryUOM' => 'PCS',
                'SalesUnit' => 'PCS',
                'BarCode' => '8990001',
                'Valid' => 'tYES',
                'Frozen' => 'tNO',
                'InventoryItem' => 'tYES',
                'AvgStdPrice' => 7000,
                'UpdateDate' => '2026-11-04T00:00:00Z',
                'UpdateTime' => '14:49:42',
                'ItemWarehouseInfoCollection' => [
                    ['WarehouseCode' => 'WH-01', 'InStock' => 25, 'Committed' => 2, 'Ordered' => 5, 'StandardAveragePrice' => 7000],
                    ['WarehouseCode' => 'GL99', 'InStock' => 99, 'Committed' => 0, 'Ordered' => 0, 'StandardAveragePrice' => 1],
                ],
                'ItemPrices' => [
                    ['PriceList' => 1, 'Price' => 15000],
                    ['PriceList' => 2, 'Price' => 13500],
                    ['PriceList' => 3, 'Price' => 9000],
                ],
            ]]]),
            '*/BusinessPartnerGroups*' => Http::response(['value' => [['Code' => 101, 'Name' => 'Grosir']]]),
            '*/BusinessPartners*' => Http::response(['value' => [[
                'CardCode' => 'C0001',
                'CardName' => 'Budi',
                'GroupCode' => 101,
                'Cellular' => '0812345',
                'EmailAddress' => 'budi@example.com',
                'Address' => 'Jakarta',
                'Valid' => 'tYES',
                'Frozen' => 'tNO',
                'UpdateDate' => '2026-11-04T00:00:00Z',
                'UpdateTime' => '16:20:14',
            ]]]),
        ]);
    }

    public function test_impor_awal_membawa_semua_jenis_data_dari_sap(): void
    {
        $this->fakeSap();
        // Tax Group manual berkode sama diambil alih SAP dan tarif PPN perusahaan ikut diperbarui.
        $manual = TaxGroup::factory()->create(['company_id' => $this->company->id, 'code' => 'PPNK-11', 'rate' => 9]);
        $this->company->update(['tax_group_id' => $manual->id, 'ppn_rate' => 9]);

        $this->actingAs($this->owner)
            ->post(route('admin.sap-import.run'), ['mode' => 'initial'])
            ->assertRedirect();

        $run = SapSyncRun::first();
        $this->assertSame(SapSyncRun::STATUS_SUCCESS, $run->status);

        $this->assertEqualsCanonicalizing(['PPNK-11', 'PPNK-0'], TaxGroup::pluck('code')->all());
        $ppn11 = TaxGroup::where('code', 'PPNK-11')->firstOrFail();
        $this->assertSame('11.0000', $ppn11->rate);
        $this->assertSame('2025-01-01', $ppn11->effective_from->toDateString());
        $this->assertSame(TaxGroup::SOURCE_SAP, $ppn11->source);
        $this->assertSame('11.00', $this->company->fresh()->ppn_rate);
        $this->assertSame(3, Warehouse::count());
        $this->assertFalse(Warehouse::where('code', 'GL99')->value('is_retail'));
        $this->assertTrue(Warehouse::where('code', 'WH-01')->value('is_retail'));
        $this->assertSame('PC-JKT', Warehouse::where('code', 'WH-01')->value('profit_center'));
        $this->assertSame('1100-1001-0002', Warehouse::where('code', 'WH-01')->value('cash_account'));
        $this->assertNull(Warehouse::where('code', 'GL99')->value('cash_account'));
        $this->assertTrue(Warehouse::where('code', 'WH-01')->value('is_active'));
        $inactive = Warehouse::where('code', 'WH-02')->firstOrFail();
        $this->assertNull($inactive->profit_center);
        $this->assertFalse($inactive->is_active);

        // Saldo easyPOS dibangun dari mutasi OIVL; saldo OITW semua warehouse hanya untuk rekonsiliasi.
        $this->assertSame(1, WarehouseStockLedger::count());
        $this->assertSame(1, $run->summary['stock_mutation']['created']);
        $this->assertSame(2, WarehouseStock::count());
        $stock = WarehouseStock::where('warehouse_id', Warehouse::where('code', 'WH-01')->value('id'))->firstOrFail();
        $this->assertSame('25.000', $stock->on_hand);
        $this->assertSame('25.000', $stock->sap_on_hand);
        $this->assertNotNull($stock->sap_checked_at);
        $nonRetail = WarehouseStock::where('warehouse_id', Warehouse::where('code', 'GL99')->value('id'))->firstOrFail();
        $this->assertSame('0.000', $nonRetail->on_hand);
        $this->assertSame('99.000', $nonRetail->sap_on_hand);
        $this->assertSame('2.000', $stock->committed);
        $this->assertSame('5.000', $stock->on_order);
        // Cost item hanya dilihat di SAP: tidak diimpor.
        $this->assertSame(0.0, (float) $stock->getRawOriginal('avg_price'));

        $this->assertSame('Pieces', Unit::where('code', 'PCS')->value('name'));
        $this->assertSame('Minuman', ProductGroup::where('code', '100')->value('name'));
        $this->assertSame('Indo Coffee', Brand::where('code', '5')->value('name'));
        $this->assertSame(2, PriceList::count());
        $this->assertSame(0, PriceList::where('code', '3')->count());
        $this->assertSame(1, $run->summary['price_list']['skipped']);
        $this->assertSame('Grosir', CustomerGroup::where('code', '101')->value('name'));
        $this->assertSame(CustomerGroup::SOURCE_SAP, CustomerGroup::where('code', '101')->value('source'));

        $product = Product::where('sku', 'ITM-001')->firstOrFail();
        $this->assertSame('Kopi Susu', $product->name);
        $this->assertSame('15000.00', $product->base_price);
        $this->assertSame(0.0, (float) $product->getRawOriginal('cost_price'));
        $this->assertSame(Brand::where('code', '5')->value('id'), $product->brand_id);
        $this->assertSame(2, PriceListItem::where('product_id', $product->id)->count());

        $customer = Customer::where('code', 'C0001')->firstOrFail();
        $this->assertSame('Budi', $customer->name);
        $this->assertSame(Customer::SOURCE_SAP, $customer->source);
        $this->assertSame(CustomerGroup::where('code', '101')->value('id'), $customer->customer_group_id);

        $this->assertNotNull($this->company->fresh()->sap_initial_imported_at);
        $this->assertSame(1, $run->summary['item_master']['created']);

        $this->assertSame(
            ['tax_group', 'warehouse', 'item_category', 'manufacturer', 'unit', 'customer_group', 'customer', 'item_master', 'price_list', 'price_list_detail', 'stock_mutation', 'warehouse_stock'],
            array_keys($run->summary)
        );
        foreach ($run->summary as $progress) {
            $this->assertSame('done', $progress['status']);
        }

        $this->actingAs($this->owner)
            ->get(route('admin.sap-import.index'))
            ->assertOk()
            ->assertSee('check_circle');
    }

    public function test_impor_awal_tidak_boleh_diulang_setelah_selesai(): void
    {
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);

        $this->actingAs($this->owner)
            ->post(route('admin.sap-import.run'), ['mode' => 'initial'])
            ->assertSessionHas('error');

        $this->assertSame(1, SapSyncRun::count());
    }

    public function test_impor_awal_yang_terhenti_bisa_diulang_dengan_melewati_baris_yang_sudah_ada(): void
    {
        $this->fakeSap();
        $existing = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Sisa impor sebelumnya']);

        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);

        $run = SapSyncRun::first();
        $this->assertSame(SapSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->summary['unit']['skipped']);
        $this->assertSame(1, Unit::count());
        $this->assertSame($existing->id, Product::where('sku', 'ITM-001')->value('unit_id'));
    }

    public function test_impor_yang_macet_di_antrean_bisa_dibatalkan_lalu_dimulai_ulang(): void
    {
        Queue::fake();

        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);
        $this->assertSame(SapSyncRun::STATUS_QUEUED, SapSyncRun::first()->status);

        // Selama masih antre, impor baru ditolak.
        $this->actingAs($this->owner)
            ->post(route('admin.sap-import.run'), ['mode' => 'initial'])
            ->assertSessionHas('error');

        $this->actingAs($this->owner)->post(route('admin.sap-import.cancel'))->assertSessionHas('success');
        $this->assertSame(SapSyncRun::STATUS_CANCELLED, SapSyncRun::first()->status);

        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);
        $this->assertSame(2, SapSyncRun::count());
    }

    public function test_job_yang_sudah_dibatalkan_tidak_menjalankan_impor(): void
    {
        $this->fakeSap();
        $run = SapSyncRun::create([
            'company_id' => $this->company->id,
            'mode' => 'initial',
            'trigger' => 'manual',
            'status' => SapSyncRun::STATUS_CANCELLED,
        ]);

        (new RunSapImport($run->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(SapSyncRun::STATUS_CANCELLED, $run->fresh()->status);
    }

    public function test_objek_yang_gagal_ditandai_dan_objek_sesudahnya_tetap_menunggu(): void
    {
        Http::swap(new HttpFactory);
        Http::fake([
            '*/Login' => Http::response([], 200),
            '*/Logout' => Http::response('', 204),
            '*/VatGroups*' => Http::response(['value' => []]),
            '*/Warehouses*' => Http::response(['value' => []]),
            '*/ItemGroups*' => Http::response(['value' => [['Number' => 100, 'GroupName' => 'Minuman']]]),
            '*/Manufacturers*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);

        $run = SapSyncRun::first();
        $this->assertSame(SapSyncRun::STATUS_FAILED, $run->status);
        $this->assertSame('done', $run->summary['item_category']['status']);
        $this->assertSame('failed', $run->summary['manufacturer']['status']);
        $this->assertSame('pending', $run->summary['unit']['status']);
    }

    public function test_sinkronisasi_perubahan_hanya_meminta_data_yang_berubah_dan_memperbarui_data(): void
    {
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);

        $this->fakeSap('Kopi Susu Gula Aren');
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'delta']);

        $this->assertSame('Kopi Susu Gula Aren', Product::where('sku', 'ITM-001')->value('name'));
        $this->assertSame(1, Product::count());

        $deltaRun = SapSyncRun::where('mode', SapSyncRun::MODE_DELTA)->firstOrFail();
        $this->assertSame(1, $deltaRun->summary['item_master']['updated']);

        // Hanya yang berubah sejak penanda waktu terakhir (jam SAP) yang diminta.
        $this->assertEquals(['items' => '2026-11-04 14:49:42', 'business_partners' => '2026-11-04 16:20:14'], $this->company->fresh()->sap_sync_watermarks);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Items')
            && str_contains(urldecode($request->url()), "UpdateDate gt '2026-11-04' or (UpdateDate eq '2026-11-04' and UpdateTime ge '14:49:42')"));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/BusinessPartners?')
            && str_contains(urldecode($request->url()), "CardType eq 'cCustomer' and (UpdateDate gt '2026-11-04' or (UpdateDate eq '2026-11-04' and UpdateTime ge '16:20:14'))"));
    }

    public function test_query_mutasi_stok_gagal_tidak_menggagalkan_sinkronisasi_master(): void
    {
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);

        $this->fakeSap('Kopi Susu Gula Aren', mutationQueryFails: true);
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'delta']);

        $run = SapSyncRun::where('mode', SapSyncRun::MODE_DELTA)->firstOrFail();
        $this->assertSame(SapSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('failed', $run->summary['stock_mutation']['status']);
        $this->assertSame('done', $run->summary['warehouse_stock']['status']);
        $this->assertSame('Kopi Susu Gula Aren', Product::where('sku', 'ITM-001')->value('name'));
        $this->assertStringContainsString('504', $this->company->fresh()->sap_stock_sync_error);
        // Sesi SAP diganti baru setelah query gagal.
        $this->assertSame(2, Http::recorded(fn ($request) => str_ends_with($request->url(), '/Login'))->count());
    }

    public function test_delta_tanpa_mutasi_stok_tidak_membaca_saldo_oitw(): void
    {
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);
        $this->assertNotNull($this->company->fresh()->sap_stock_reconciled_at);

        $this->travel(31)->minutes();
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'delta']);

        $deltaRun = SapSyncRun::where('mode', SapSyncRun::MODE_DELTA)->firstOrFail();
        $this->assertSame('done', $deltaRun->summary['warehouse_stock']['status']);
        $this->assertSame(0, $deltaRun->summary['warehouse_stock']['fetched']);
        Http::assertNotSent(fn ($request) => str_contains(urldecode($request->url()), 'ItemWarehouseInfoCollection'));
    }

    public function test_delta_hanya_merekonsiliasi_item_yang_bermutasi_dan_penuh_sekali_sehari(): void
    {
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);

        // Mutasi baru sesudah sinkronisasi terakhir.
        $this->travel(31)->minutes();
        WarehouseStockService::record(Warehouse::where('code', 'WH-01')->firstOrFail(), Product::where('sku', 'ITM-001')->value('id'), WarehouseStockLedger::TYPE_GOODS_ISSUE, -1);
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'delta']);

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'ItemWarehouseInfoCollection')
            && str_contains(urldecode($request->url()), "ItemCode eq 'ITM-001'"));

        // Lebih dari sehari sejak rekonsiliasi penuh: semua item dibaca lagi.
        $this->travel(25)->hours();
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'delta']);

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'ItemWarehouseInfoCollection')
            && ! str_contains(urldecode($request->url()), 'ItemCode eq'));
    }

    public function test_sinkronisasi_tidak_menimpa_customer_easypos_yang_sudah_dikirim_ke_sap(): void
    {
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);
        // Customer C0001 dibuat di easyPOS lalu dikirim ke SAP (CardCode dikembalikan SAP).
        Customer::where('code', 'C0001')->update(['source' => Customer::SOURCE_MANUAL, 'name' => 'Budi easyPOS']);

        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'delta']);

        $customer = Customer::where('code', 'C0001')->firstOrFail();
        $this->assertSame('Budi easyPOS', $customer->name);
        $this->assertSame(Customer::SOURCE_MANUAL, $customer->source);
    }

    public function test_impor_gagal_dicatat_bila_login_sap_ditolak(): void
    {
        Http::fake(['*/Login' => Http::response(['error' => ['message' => ['value' => 'Invalid password']]], 401)]);

        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);

        $run = SapSyncRun::first();
        $this->assertSame(SapSyncRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('Invalid password', $run->error);
        $this->assertNull($this->company->fresh()->sap_initial_imported_at);
    }

    public function test_halaman_impor_hanya_tersedia_bila_integrasi_sap_aktif(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.sap-import.index'))
            ->assertOk()
            ->assertSee('Impor Data Awal');

        $this->company->update(['sap_enabled' => false]);

        $this->actingAs($this->owner)
            ->get(route('admin.sap-import.index'))
            ->assertRedirect(route('admin.company.edit'));
    }

    public function test_interval_sinkronisasi_default_30_menit_dan_bisa_diubah(): void
    {
        $this->assertSame(30, $this->company->fresh()->sap_sync_interval_minutes);

        $this->actingAs($this->owner)
            ->put(route('admin.sap-import.settings'), ['sap_sync_interval_minutes' => 5, 'sap_auto_sync' => 1])
            ->assertRedirect();

        $this->assertSame(5, $this->company->fresh()->sap_sync_interval_minutes);

        $this->actingAs($this->owner)
            ->put(route('admin.sap-import.settings'), ['sap_sync_interval_minutes' => 0])
            ->assertSessionHasErrors('sap_sync_interval_minutes');
    }

    public function test_kasir_tidak_bisa_menjalankan_impor(): void
    {
        $cashier = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->owner->store_id,
            'name' => 'Kasir',
            'username' => 'kasir1',
            'role' => User::ROLE_CASHIER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->actingAs($cashier)
            ->post(route('admin.sap-import.run'), ['mode' => 'initial'])
            ->assertForbidden();

        $this->assertSame(0, SapSyncRun::count());
    }

    public function test_scheduler_menunggu_impor_awal_dan_mengikuti_interval(): void
    {
        Queue::fake();

        // Belum ada impor awal → tidak menjadwalkan apa pun.
        $this->artisan('sap:sync')->assertSuccessful();
        $this->assertSame(0, SapSyncRun::count());

        // Interval belum terlewati.
        $this->company->update([
            'sap_initial_imported_at' => now()->subHour(),
            'sap_last_synced_at' => now()->subMinutes(10),
            'sap_sync_interval_minutes' => 30,
        ]);
        $this->artisan('sap:sync')->assertSuccessful();
        $this->assertSame(0, SapSyncRun::count());

        // Interval terlewati → run delta terjadwal dibuat.
        $this->company->update(['sap_last_synced_at' => now()->subMinutes(31)]);
        $this->artisan('sap:sync')->assertSuccessful();

        $this->assertSame(1, SapSyncRun::where('mode', SapSyncRun::MODE_DELTA)->where('trigger', 'schedule')->count());

        // Run sebelumnya masih antre → tidak dobel.
        $this->artisan('sap:sync')->assertSuccessful();
        $this->assertSame(1, SapSyncRun::count());
    }
}
