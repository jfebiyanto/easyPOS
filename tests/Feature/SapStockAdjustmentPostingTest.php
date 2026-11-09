<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockAdjustment;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferReturn;
use App\Models\StockTransferShipment;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\Sap\SapStockMutationService;
use App\Support\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class SapStockAdjustmentPostingTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private Warehouse $warehouse;

    private User $owner;

    private User $manager;

    private Product $kopi;

    private Product $teh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co', 'allow_negative_stock' => false, 'sap_enabled' => true, 'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1', 'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager', 'sap_password' => 'secret',
        ]);
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id, 'code' => 'G.001.MN']);
        $this->store = Store::create([
            'company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC',
            'currency' => 'IDR', 'warehouse_id' => $this->warehouse->id,
        ]);
        $this->owner = User::create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Owner',
            'username' => 'owner1', 'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password',
        ]);
        $this->manager = User::create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'SM',
            'username' => 'sm1', 'role' => User::ROLE_STORE_MANAGER, 'is_active' => true, 'password' => 'password',
        ]);

        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum']);
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $make = fn (string $sku) => Product::create([
            'company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id,
            'sku' => $sku, 'name' => $sku, 'base_price' => 4000, 'track_stock' => true, 'is_active' => true,
        ]);
        $this->kopi = $make('KOPI-01');
        $this->teh = $make('TEH-01');

        WarehouseStockService::record($this->warehouse, $this->kopi->id, 'opening', 10, ['created_by' => null]);
        WarehouseStockService::record($this->warehouse, $this->teh->id, 'opening', 10, ['created_by' => null]);
    }

    /**
     * @param  array{entry?: ?array<string, mixed>, exit?: ?array<string, mixed>, exitFails?: bool}  $options  dokumen yang sudah ada di SAP (pencarian U_SOL_TRKY)
     */
    private function fakeSap(array $options = []): void
    {
        Http::swap(new HttpFactory);

        Http::fake(function (Request $request) use ($options) {
            $url = $request->url();
            $get = $request->method() === 'GET';

            return match (true) {
                str_ends_with($url, '/Login') => Http::response(['SessionId' => 'abc']),
                str_ends_with($url, '/Logout') => Http::response('', 204),
                $get && str_contains($url, '/InventoryGenEntries') => Http::response(['value' => array_filter([$options['entry'] ?? null])]),
                $get && str_contains($url, '/InventoryGenExits') => Http::response(['value' => array_filter([$options['exit'] ?? null])]),
                ($options['exitFails'] ?? false) && str_ends_with($url, '/InventoryGenExits') => Http::response(['error' => ['message' => ['value' => 'Quantity falls into negative inventory']]], 400),
                str_ends_with($url, '/InventoryGenEntries') => Http::response(['DocEntry' => 31, 'DocNum' => 3100001], 201),
                str_ends_with($url, '/InventoryGenExits') => Http::response(['DocEntry' => 32, 'DocNum' => 3200001], 201),
                default => Http::response('', 204),
            };
        });
    }

    /**
     * Buat draft lalu Post sebagai $by.
     *
     * @param  array<string, float>  $after  SKU => qty sesudah
     */
    private function adjust(array $after, ?User $by = null, string $reason = 'Stock opname'): StockAdjustment
    {
        $this->actingAs($by ?? $this->owner)->post(route('admin.stock-adjustments.store'), [
            'store_id' => $this->store->id,
            'date' => '2026-11-09',
            'reason' => $reason,
            'lines' => collect($after)->map(fn (float $qty, string $sku) => [
                'product_id' => Product::where('sku', $sku)->value('id'), 'qty_after' => $qty,
            ])->values()->all(),
        ])->assertRedirect();

        $adjustment = StockAdjustment::latest('created_at')->firstOrFail();
        $this->post(route('admin.stock-adjustments.post', $adjustment));

        return $adjustment->fresh();
    }

    private function onHand(Product $product): float
    {
        return (float) WarehouseStock::where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->value('on_hand');
    }

    public function test_selisih_campur_diposting_sebagai_goods_receipt_dan_goods_issue(): void
    {
        $this->fakeSap();

        $adjustment = $this->adjust(['KOPI-01' => 13, 'TEH-01' => 8]);

        $this->assertSame(StockAdjustment::SAP_POSTED, $adjustment->sap_post_status);
        $this->assertSame([59, '3100001', 31], [$adjustment->sap_receipt_obj_type, $adjustment->sap_receipt_doc_num, $adjustment->sap_receipt_doc_entry]);
        $this->assertSame([60, '3200001', 32], [$adjustment->sap_issue_obj_type, $adjustment->sap_issue_doc_num, $adjustment->sap_issue_doc_entry]);
        $this->assertNotNull($adjustment->sap_posted_at);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/InventoryGenEntries')
            && $request['U_SOL_FRST'] === 'Y'
            && $request['U_SOL_TRKY'] === $adjustment->id
            && $request['U_SOL_STID'] === $this->store->id
            && $request['JournalMemo'] === $adjustment->number
            && $request['Comments'] === 'Stock opname'
            && $request['DocDate'] === '2026-11-09'
            && $request['DocumentLines'] === [['ItemCode' => 'KOPI-01', 'Quantity' => 3.0, 'WarehouseCode' => 'G.001.MN']]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/InventoryGenExits')
            && $request['U_SOL_TRKY'] === $adjustment->id
            && $request['DocumentLines'] === [['ItemCode' => 'TEH-01', 'Quantity' => 2.0, 'WarehouseCode' => 'G.001.MN']]);
    }

    public function test_profit_center_warehouse_dicatat_di_costing_code_baris_goods_receipt_dan_issue(): void
    {
        $this->fakeSap();
        $this->warehouse->update(['profit_center' => 'R001']);

        $this->adjust(['KOPI-01' => 13, 'TEH-01' => 8]);

        foreach (['InventoryGenEntries', 'InventoryGenExits'] as $resource) {
            Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/'.$resource)
                && collect($request['DocumentLines'])->every(fn (array $line) => ($line['CostingCode'] ?? null) === 'R001'));
        }
    }

    public function test_post_mengubah_saldo_dan_buku_besar_warehouse(): void
    {
        $this->fakeSap();

        $adjustment = $this->adjust(['KOPI-01' => 13, 'TEH-01' => 8]);

        $this->assertSame(13.0, $this->onHand($this->kopi));
        $this->assertSame(8.0, $this->onHand($this->teh));
        $this->assertDatabaseHas('warehouse_stock_ledgers', [
            'warehouse_id' => $this->warehouse->id, 'ref_type' => 'stock_adjustment', 'ref_id' => $adjustment->id,
            'product_id' => $this->teh->id, 'type' => 'adjustment',
        ]);
    }

    public function test_qty_sama_dengan_saldo_tidak_membuat_penyesuaian(): void
    {
        $this->fakeSap();

        $this->actingAs($this->owner)->post(route('admin.stock-adjustments.store'), [
            'store_id' => $this->store->id, 'date' => '2026-11-09',
            'lines' => [['product_id' => $this->kopi->id, 'qty_after' => 10]],
        ])->assertSessionHas('error');

        $this->assertSame(0, StockAdjustment::count());
        Http::assertNothingSent();
    }

    public function test_baris_tanpa_selisih_dilewati_dan_baris_berselisih_tetap_diposting(): void
    {
        $this->fakeSap();

        $adjustment = $this->adjust(['KOPI-01' => 10, 'TEH-01' => 8]);

        $this->assertSame(1, $adjustment->lines()->count());
        $this->assertSame(StockAdjustment::SAP_POSTED, $adjustment->sap_post_status);
        $this->assertSame('3200001', $adjustment->sap_issue_doc_num);
    }

    public function test_draft_lama_tanpa_selisih_tidak_bisa_diposting(): void
    {
        $this->fakeSap();
        $adjustment = StockAdjustment::create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'date' => '2026-11-09',
            'status' => StockAdjustment::STATUS_DRAFT, 'created_by' => $this->owner->id,
        ]);
        $adjustment->lines()->create(['product_id' => $this->kopi->id, 'qty_before' => 10, 'qty_after' => 10, 'delta' => 0]);

        $this->actingAs($this->owner)->post(route('admin.stock-adjustments.post', $adjustment))->assertStatus(422);

        $this->assertSame(StockAdjustment::STATUS_DRAFT, $adjustment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_form_mengambil_saldo_saat_ini_dari_warehouse_gerai(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('admin.stock-adjustments.balance', ['store_id' => $this->store->id, 'product_id' => $this->kopi->id]))
            ->assertOk()
            ->assertJsonPath('balance', 10);

        // Store Manager selalu memakai gerainya sendiri, store_id dari request diabaikan.
        $this->actingAs($this->manager)
            ->getJson(route('admin.stock-adjustments.balance', ['product_id' => $this->teh->id]))
            ->assertOk()
            ->assertJsonPath('balance', 10);

        $this->actingAs($this->owner)
            ->getJson(route('admin.stock-adjustments.balance', ['store_id' => 'bukan-gerai', 'product_id' => $this->kopi->id]))
            ->assertStatus(422);

        $this->getJson(route('admin.stock-adjustments.balance'))->assertStatus(422);
    }

    public function test_form_penyesuaian_menampilkan_petunjuk_saldo_saat_ini(): void
    {
        $this->actingAs($this->owner)->get(route('admin.stock-adjustments.create'))
            ->assertOk()
            ->assertSee('Current stock')
            ->assertSee(json_encode(route('admin.stock-adjustments.balance')), false);
    }

    public function test_selisih_positif_saja_hanya_membuat_goods_receipt(): void
    {
        $this->fakeSap();

        $adjustment = $this->adjust(['KOPI-01' => 12]);

        $this->assertSame(StockAdjustment::SAP_POSTED, $adjustment->sap_post_status);
        $this->assertNull($adjustment->sap_issue_doc_num);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/InventoryGenExits'));
    }

    public function test_goods_issue_gagal_menandai_failed_dan_kirim_ulang_melanjutkan_tanpa_dokumen_ganda(): void
    {
        $this->fakeSap(['exitFails' => true]);
        $adjustment = $this->adjust(['KOPI-01' => 13, 'TEH-01' => 8]);

        $this->assertSame(StockAdjustment::SAP_FAILED, $adjustment->sap_post_status);
        $this->assertStringContainsString('negative inventory', $adjustment->sap_post_error);
        $this->assertSame('3100001', $adjustment->sap_receipt_doc_num);
        $this->assertNull($adjustment->sap_issue_doc_num);

        // Kirim ulang: Goods Receipt yang sudah ada tidak dibuat lagi; Goods Issue dicari dulu lewat U_SOL_TRKY.
        $this->fakeSap();
        $this->post(route('admin.stock-adjustments.retry', $adjustment))->assertSessionHas('success');

        $adjustment->refresh();
        $this->assertSame(StockAdjustment::SAP_POSTED, $adjustment->sap_post_status);
        $this->assertSame('3200001', $adjustment->sap_issue_doc_num);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/InventoryGenEntries'));
    }

    public function test_kirim_ulang_memakai_dokumen_sap_yang_sudah_ada_berdasarkan_tracking_key(): void
    {
        $this->fakeSap(['exitFails' => true]);
        $adjustment = $this->adjust(['TEH-01' => 8]);
        $this->assertSame(StockAdjustment::SAP_FAILED, $adjustment->sap_post_status);

        $this->fakeSap(['exit' => ['DocEntry' => 32, 'DocNum' => 3200001]]);
        $this->post(route('admin.stock-adjustments.retry', $adjustment))->assertSessionHas('success');

        $this->assertSame(StockAdjustment::SAP_POSTED, $adjustment->fresh()->sap_post_status);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/InventoryGenExits'));
    }

    public function test_post_ditolak_untuk_gerai_berwarehouse_bila_integrasi_sap_nonaktif(): void
    {
        $this->company->update(['sap_enabled' => false]);
        $this->fakeSap();

        $adjustment = $this->adjust(['KOPI-01' => 13]);

        $this->assertSame(StockAdjustment::STATUS_DRAFT, $adjustment->status);
        $this->assertSame(10.0, $this->onHand($this->kopi));
        Http::assertNothingSent();
    }

    public function test_selisih_yang_membuat_saldo_warehouse_negatif_ditolak(): void
    {
        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.stock-adjustments.store'), [
            'store_id' => $this->store->id, 'date' => '2026-11-09',
            'lines' => [['product_id' => $this->kopi->id, 'qty_after' => -5]],
        ]);
        $adjustment = StockAdjustment::firstOrFail();

        $this->post(route('admin.stock-adjustments.post', $adjustment))->assertStatus(422);

        $this->assertSame(StockAdjustment::STATUS_DRAFT, $adjustment->fresh()->status);
        $this->assertSame(10.0, $this->onHand($this->kopi));
    }

    public function test_store_manager_dapat_post_tetapi_hanya_owner_yang_kirim_ulang(): void
    {
        $this->fakeSap(['exitFails' => true]);
        $adjustment = $this->adjust(['TEH-01' => 8], $this->manager);

        $this->assertSame(StockAdjustment::STATUS_POSTED, $adjustment->status);
        $this->assertSame(StockAdjustment::SAP_FAILED, $adjustment->sap_post_status);

        $this->actingAs($this->manager)->post(route('admin.stock-adjustments.retry', $adjustment))->assertForbidden();
    }

    public function test_tab_inventory_integration_monitor_menampilkan_transfer_dan_penyesuaian(): void
    {
        $this->fakeSap(['exitFails' => true]);
        $adjustment = $this->adjust(['KOPI-01' => 13, 'TEH-01' => 8]);

        $shipment = StockTransferShipment::create([
            'company_id' => $this->company->id, 'number' => 'SHP-0001', 'from_store_id' => $this->store->id, 'to_store_id' => $this->store->id,
            'status' => StockTransferShipment::STATUS_POSTED, 'posted_at' => now(), 'created_by' => $this->owner->id,
            'sap_post_status' => StockTransferShipment::SAP_POSTED, 'sap_doc_num' => '5000001',
        ]);
        StockTransferReceipt::create([
            'company_id' => $this->company->id, 'shipment_id' => $shipment->id, 'number' => 'RCP-0001', 'store_id' => $this->store->id,
            'received_at' => now(), 'created_by' => $this->owner->id,
            'sap_post_status' => StockTransferReceipt::SAP_FAILED, 'sap_post_error' => 'Warehouse tujuan tidak ditemukan',
        ]);
        StockTransferReturn::create([
            'company_id' => $this->company->id, 'shipment_id' => $shipment->id, 'number' => 'RET-0001', 'store_id' => $this->store->id,
            'returned_at' => now(), 'created_by' => $this->owner->id, 'sap_post_status' => StockTransferReturn::SAP_PENDING,
        ]);

        $this->actingAs($this->owner)->get(route('admin.sap-postings.inventory'))
            ->assertOk()
            ->assertSee('Transfer Shipment')->assertSee('SHP-0001')->assertSee('Stock Transfer 5000001')
            ->assertSee('Transfer Receipt')->assertSee('Warehouse tujuan tidak ditemukan')
            ->assertSee('Transfer Return')->assertSee('RET-0001')
            ->assertSee('Stock Counting')->assertSee($adjustment->number)->assertSee('Goods Receipt 3100001')
            ->assertSee('Failed (2)')->assertSee('Pending (1)')->assertSee('Posted (1)')
            ->assertSee(route('admin.stock-adjustments.retry', $adjustment), false);

        $this->get(route('admin.sap-postings.inventory', ['status' => 'pending']))
            ->assertOk()->assertSee('RET-0001')->assertDontSee('SHP-0001');

        $this->actingAs($this->manager)->get(route('admin.sap-postings.inventory'))->assertForbidden();
    }

    public function test_mutasi_goods_receipt_dan_issue_sap_ditautkan_ke_buku_besar_penyesuaian(): void
    {
        $this->fakeSap();
        $adjustment = $this->adjust(['KOPI-01' => 13, 'TEH-01' => 8]);
        $rowsBefore = WarehouseStockLedger::where('warehouse_id', $this->warehouse->id)->count();

        $service = new SapStockMutationService($this->company);
        $method = new ReflectionMethod($service, 'linkToEasyPosAdjustment');

        $receipt = ['TransSeq' => 601, 'TransType' => 59, 'CreatedBy' => 31, 'BASE_REF' => 3100001, 'DocLineNum' => 0, 'DocDate' => '20261109', 'LocCode' => 'G.001.MN', 'ItemCode' => 'KOPI-01', 'InQty' => 3, 'OutQty' => 0];
        $issue = ['TransSeq' => 602, 'TransType' => 60, 'CreatedBy' => 32, 'BASE_REF' => 3200001, 'DocLineNum' => 0, 'DocDate' => '20261109', 'LocCode' => 'G.001.MN', 'ItemCode' => 'TEH-01', 'InQty' => 0, 'OutQty' => 2];

        $this->assertTrue($method->invoke($service, $receipt));
        $this->assertTrue($method->invoke($service, $issue));
        $this->assertSame([601, 602], WarehouseStockLedger::where('ref_type', 'stock_adjustment')->where('ref_id', $adjustment->id)->orderBy('sap_trans_seq')->pluck('sap_trans_seq')->all());
        $this->assertSame($rowsBefore, WarehouseStockLedger::where('warehouse_id', $this->warehouse->id)->count());
    }
}
