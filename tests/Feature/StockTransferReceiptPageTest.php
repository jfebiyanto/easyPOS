<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferShipment;
use App\Models\StockTransferShipmentLine;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\StockTransferService;
use App\Support\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StockTransferReceiptPageTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    /** @var array<string, Store> */
    private array $stores = [];

    /** @var array<string, Warehouse> */
    private array $warehouses = [];

    private User $owner;

    private User $managerA;

    private User $managerB;

    /** @var array<string, Product> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co', 'allow_negative_stock' => false, 'sap_enabled' => true, 'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1', 'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager', 'sap_password' => 'secret',
        ]);

        foreach (['A', 'B', 'C'] as $i => $key) {
            Warehouse::factory()->transit()->create(['company_id' => $this->company->id, 'code' => "G.00{$i}.IT"]);
            $this->warehouses[$key] = Warehouse::factory()->create([
                'company_id' => $this->company->id, 'code' => "G.00{$i}.MN", 'transit_warehouse_code' => "G.00{$i}.IT",
            ]);
            $this->stores[$key] = Store::create([
                'company_id' => $this->company->id, 'code' => "STR00{$i}", 'name' => "Store {$key}", 'pic_name' => 'PIC',
                'currency' => 'IDR', 'warehouse_id' => $this->warehouses[$key]->id,
            ]);
        }

        $this->owner = $this->makeUser('owner1', User::ROLE_OWNER, 'A');
        $this->managerA = $this->makeUser('managera', User::ROLE_STORE_MANAGER, 'A');
        $this->managerB = $this->makeUser('managerb', User::ROLE_STORE_MANAGER, 'B');

        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum']);
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);

        foreach (['INDF-0001' => 'Indomie', 'MINU-0002' => 'Teh Botol'] as $sku => $name) {
            $this->products[$sku] = Product::create([
                'company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id,
                'sku' => $sku, 'name' => $name, 'base_price' => 4000, 'track_stock' => true, 'is_active' => true,
            ]);
            WarehouseStockService::record($this->warehouses['A'], $this->products[$sku]->id, WarehouseStockLedger::TYPE_OPENING, 50, ['created_by' => null]);
        }
    }

    private function makeUser(string $username, string $role, string $storeKey): User
    {
        return User::create([
            'company_id' => $this->company->id, 'store_id' => $this->stores[$storeKey]->id, 'name' => ucfirst($username),
            'username' => $username, 'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    /**
     * @param  array<string, float|int>  $qtyBySku
     */
    private function postedShipment(string $from, string $to, array $qtyBySku = ['INDF-0001' => 10, 'MINU-0002' => 6]): StockTransferShipment
    {
        $shipment = StockTransferShipment::create([
            'company_id' => $this->company->id, 'from_store_id' => $this->stores[$from]->id,
            'to_store_id' => $this->stores[$to]->id, 'status' => StockTransferShipment::STATUS_DRAFT,
            'created_by' => $this->owner->id,
        ]);

        foreach ($qtyBySku as $sku => $qty) {
            StockTransferShipmentLine::create(['shipment_id' => $shipment->id, 'product_id' => $this->products[$sku]->id, 'qty' => $qty, 'qty_received' => 0]);
        }

        (new StockTransferService)->post($shipment, $this->owner);

        return $shipment->fresh(['lines.product']);
    }

    private function lineFor(StockTransferShipment $shipment, string $sku): StockTransferShipmentLine
    {
        return $shipment->lines()->where('product_id', $this->products[$sku]->id)->firstOrFail();
    }

    private function onHand(string $warehouseKey, string $sku): float
    {
        return (float) WarehouseStock::where('warehouse_id', $this->warehouses[$warehouseKey]->id)
            ->where('product_id', $this->products[$sku]->id)->value('on_hand');
    }

    public function test_create_dengan_shipment_menampilkan_semua_baris_bersisa_terisi_sisa(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $partial = $this->lineFor($shipment, 'INDF-0001');
        (new StockTransferService)->receive($shipment, [$partial->id => 4], $this->owner);
        $exhausted = $this->lineFor($shipment, 'MINU-0002');
        (new StockTransferService)->receive($shipment, [$exhausted->id => 6], $this->owner);

        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-receipts.create', ['shipment' => $shipment->id]))
            ->assertOk()
            ->assertSee('INDF-0001')
            ->assertDontSee('MINU-0002')
            ->assertSee('name="lines['.$partial->id.'][qty]"', false)
            ->assertSee('value="6"', false)
            ->assertSee('max="6"', false)
            ->assertDontSee('lines['.$exhausted->id.'][qty]', false);
    }

    public function test_create_tanpa_shipment_menampilkan_pemilih_dan_empty_state(): void
    {
        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-receipts.create'))
            ->assertOk()->assertSee('No shipments awaiting receipt.');

        $shipment = $this->postedShipment('A', 'B');
        $this->postedShipment('A', 'C');

        $response = $this->actingAs($this->managerB)->get(route('admin.stock-transfer-receipts.create'))
            ->assertOk()->assertSee($shipment->number)->assertDontSee('No shipments awaiting receipt.');

        $this->assertCount(1, $response->viewData('receivable'));
    }

    public function test_kirim_dengan_satu_baris_dihapus_tidak_menyentuh_baris_itu(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $kept = $this->lineFor($shipment, 'INDF-0001');
        $removed = $this->lineFor($shipment, 'MINU-0002');

        $this->actingAs($this->managerB)->post(route('admin.stock-transfer-receipts.store'), [
            'shipment_id' => $shipment->id,
            'lines' => [$kept->id => ['qty' => 10]],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(10.0, (float) $kept->fresh()->qty_received);
        $this->assertSame(0.0, (float) $removed->fresh()->qty_received);
        $this->assertSame(6.0, $removed->fresh()->remaining());
        $this->assertSame(10.0, $this->onHand('B', 'INDF-0001'));
        $this->assertSame(0.0, $this->onHand('B', 'MINU-0002'));
    }

    public function test_qty_lebih_kecil_menjadikan_shipment_partially_received(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $indomie = $this->lineFor($shipment, 'INDF-0001');
        $tea = $this->lineFor($shipment, 'MINU-0002');

        $response = $this->actingAs($this->managerB)->post(route('admin.stock-transfer-receipts.store'), [
            'shipment_id' => $shipment->id,
            'note' => 'Sebagian rusak',
            'lines' => [$indomie->id => ['qty' => 7], $tea->id => ['qty' => 6]],
        ]);

        $receipt = StockTransferReceipt::firstOrFail();
        $response->assertRedirect(route('admin.stock-transfer-receipts.show', $receipt));
        $this->assertSame(7.0, (float) $indomie->fresh()->qty_received);
        $this->assertSame('Partially Received', $shipment->fresh(['lines'])->displayStatus());
        $this->assertSame('Sebagian rusak', $receipt->note);
        $this->assertDatabaseHas('activity_logs', ['action' => 'stock.receipt_created']);
    }

    public function test_qty_melebihi_sisa_ditolak_tanpa_mengubah_stok(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $indomie = $this->lineFor($shipment, 'INDF-0001');

        $this->actingAs($this->managerB)->post(route('admin.stock-transfer-receipts.store'), [
            'shipment_id' => $shipment->id,
            'lines' => [$indomie->id => ['qty' => 11]],
        ])->assertRedirect()->assertSessionHas('error', fn (string $message) => str_contains($message, 'exceeds remaining'));

        $this->assertSame(0, StockTransferReceipt::count());
        $this->assertSame(0.0, (float) $indomie->fresh()->qty_received);
        $this->assertSame(0.0, $this->onHand('B', 'INDF-0001'));
    }

    public function test_semua_baris_dihapus_ditolak(): void
    {
        $shipment = $this->postedShipment('A', 'B');

        $this->actingAs($this->managerB)->post(route('admin.stock-transfer-receipts.store'), [
            'shipment_id' => $shipment->id,
        ])->assertRedirect()->assertSessionHasErrors('lines');

        $this->assertSame(0, StockTransferReceipt::count());
    }

    public function test_shipment_gerai_lain_dan_gerai_pengirim_ditolak(): void
    {
        $toB = $this->postedShipment('A', 'B');
        $toC = $this->postedShipment('A', 'C');
        $line = $this->lineFor($toB, 'INDF-0001');

        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-receipts.create', ['shipment' => $toC->id]))->assertForbidden();
        $this->actingAs($this->managerB)->post(route('admin.stock-transfer-receipts.store'), [
            'shipment_id' => $toC->id,
            'lines' => [$this->lineFor($toC, 'INDF-0001')->id => ['qty' => 1]],
        ])->assertForbidden();

        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-receipts.create', ['shipment' => $toB->id]))->assertForbidden();
        $this->actingAs($this->managerA)->post(route('admin.stock-transfer-receipts.store'), [
            'shipment_id' => $toB->id,
            'lines' => [$line->id => ['qty' => 1]],
        ])->assertForbidden();

        $this->assertSame(0, StockTransferReceipt::count());
    }

    public function test_tombol_receive_di_daftar_shipment_mengarah_ke_form_terisi(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $url = route('admin.stock-transfer-receipts.create', ['shipment' => $shipment->id]);

        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-shipments.index'))
            ->assertOk()->assertSee('Receive')->assertSee($url, false);
        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-shipments.index'))
            ->assertOk()->assertDontSee($url, false);

        $this->actingAs($this->managerB)->get($url)->assertOk()->assertSee('INDF-0001');
    }

    public function test_menu_menampilkan_shipment_dan_receipt(): void
    {
        $this->actingAs($this->owner)->get(route('admin.stock-transfer-receipts.index'))
            ->assertOk()->assertSee('Stock Transfer Shipment')->assertSee('Stock Transfer Receipt');
    }

    public function test_retry_hanya_owner_dan_hanya_failed(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $receipt = (new StockTransferService)->receive($shipment, [$this->lineFor($shipment, 'INDF-0001')->id => 1], $this->owner);
        $receipt->forceFill(['sap_post_status' => StockTransferReceipt::SAP_FAILED, 'sap_post_error' => 'boom'])->save();

        $this->actingAs($this->managerB)->post(route('admin.stock-transfer-receipts.retry', $receipt))->assertForbidden();
        $this->assertSame(StockTransferReceipt::SAP_FAILED, $receipt->fresh()->sap_post_status);

        $this->actingAs($this->owner)->get(route('admin.stock-transfer-receipts.index'))->assertSee('Retry');
        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-receipts.index'))->assertDontSee('Retry');

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-receipts.retry', $receipt))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(StockTransferReceipt::SAP_PENDING, $receipt->fresh()->sap_post_status);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-receipts.retry', $receipt))->assertStatus(422);
    }

    public function test_retry_saat_integrasi_mati_menampilkan_error(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $receipt = (new StockTransferService)->receive($shipment, [$this->lineFor($shipment, 'INDF-0001')->id => 1], $this->owner);
        $receipt->forceFill(['sap_post_status' => StockTransferReceipt::SAP_FAILED])->save();
        $this->company->update(['sap_enabled' => false]);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-receipts.retry', $receipt))
            ->assertRedirect()->assertSessionHas('error')->assertSessionMissing('success');
        $this->assertSame(StockTransferReceipt::SAP_FAILED, $receipt->fresh()->sap_post_status);
    }

    public function test_index_dan_show_menampilkan_docnum_tanpa_docentry(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $receipt = (new StockTransferService)->receive($shipment, [$this->lineFor($shipment, 'INDF-0001')->id => 3], $this->owner);
        $receipt->forceFill(['sap_post_status' => StockTransferReceipt::SAP_POSTED, 'sap_doc_num' => 5521, 'sap_doc_entry' => 87654])->save();

        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-receipts.index'))
            ->assertOk()->assertSee($receipt->number)->assertSee('67 5521')->assertDontSee('87654');
        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-receipts.show', $receipt))
            ->assertOk()->assertSee('INDF-0001')->assertSee('67 5521')->assertDontSee('87654');
        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-receipts.show', $receipt))->assertOk();
    }
}
