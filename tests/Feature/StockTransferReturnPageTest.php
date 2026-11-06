<?php

namespace Tests\Feature;

use App\Jobs\PostStockTransferToSap;
use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockTransferReturn;
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

class StockTransferReturnPageTest extends TestCase
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

    private function inTransit(string $warehouseKey, string $sku): float
    {
        $transit = Warehouse::where('code', $this->warehouses[$warehouseKey]->transit_warehouse_code)->firstOrFail();

        return (float) WarehouseStock::where('warehouse_id', $transit->id)
            ->where('product_id', $this->products[$sku]->id)->value('on_hand');
    }

    public function test_create_dengan_shipment_menampilkan_baris_bersisa_dan_menyembunyikan_yang_habis(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $partial = $this->lineFor($shipment, 'INDF-0001');
        (new StockTransferService)->receive($shipment, [$partial->id => 4], $this->owner);
        $exhausted = $this->lineFor($shipment, 'MINU-0002');
        (new StockTransferService)->receive($shipment, [$exhausted->id => 6], $this->owner);

        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-returns.create', ['shipment' => $shipment->id]))
            ->assertOk()
            ->assertSee('INDF-0001')
            ->assertDontSee('MINU-0002')
            ->assertSee('name="lines['.$partial->id.'][qty]"', false)
            ->assertSee('value="6"', false)
            ->assertSee('max="6"', false)
            ->assertSee('maxlength="254"', false)
            ->assertSee('sent to SAP as Comments')
            ->assertDontSee('lines['.$exhausted->id.'][qty]', false);
    }

    public function test_create_tanpa_shipment_menampilkan_pemilih_shipment_pengirim_yang_bersisa(): void
    {
        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-returns.create'))
            ->assertOk()->assertSee('No shipments available to return.');

        $mine = $this->postedShipment('A', 'B');
        WarehouseStockService::record($this->warehouses['B'], $this->products['INDF-0001']->id, WarehouseStockLedger::TYPE_OPENING, 5, ['created_by' => null]);
        $this->postedShipment('B', 'A', ['INDF-0001' => 5]);
        $settled = $this->postedShipment('A', 'C', ['INDF-0001' => 3]);
        (new StockTransferService)->returnToSender($settled, [$this->lineFor($settled, 'INDF-0001')->id => 3], $this->owner);

        $response = $this->actingAs($this->managerA)->get(route('admin.stock-transfer-returns.create'))
            ->assertOk()->assertSee($mine->number)->assertDontSee($settled->number);

        $this->assertCount(1, $response->viewData('returnable'));
    }

    public function test_create_dengan_parameter_shipment_array_menampilkan_pemilih(): void
    {
        $shipment = $this->postedShipment('A', 'B');

        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-returns.create').'?shipment[]='.$shipment->id)
            ->assertOk()->assertSee($shipment->number);
    }

    public function test_owner_membuat_return_setelah_sebagian_diterima(): void
    {
        $shipment = $this->postedShipment('A', 'B', ['INDF-0001' => 12]);
        $line = $this->lineFor($shipment, 'INDF-0001');
        (new StockTransferService)->receive($shipment, [$line->id => 10], $this->owner);
        $before = $this->onHand('A', 'INDF-0001');

        $response = $this->actingAs($this->owner)->post(route('admin.stock-transfer-returns.store'), [
            'shipment_id' => $shipment->id,
            'note' => 'Barang salah kirim',
            'lines' => [$line->id => ['qty' => 2]],
        ]);

        $return = StockTransferReturn::firstOrFail();
        $response->assertRedirect(route('admin.stock-transfer-returns.show', $return))->assertSessionHas('success');
        $this->assertSame('Completed', $shipment->fresh(['lines'])->displayStatus());
        $this->assertSame($before + 2, $this->onHand('A', 'INDF-0001'));
        $this->assertSame(0.0, $this->inTransit('A', 'INDF-0001'));
        $this->assertSame('Barang salah kirim', $return->note);
        $this->assertDatabaseHas('activity_logs', ['action' => 'stock.return_created']);
    }

    public function test_baris_yang_dihapus_tidak_tersentuh(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $kept = $this->lineFor($shipment, 'INDF-0001');
        $removed = $this->lineFor($shipment, 'MINU-0002');

        $this->actingAs($this->managerA)->post(route('admin.stock-transfer-returns.store'), [
            'shipment_id' => $shipment->id,
            'lines' => [$kept->id => ['qty' => 4]],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(4.0, (float) $kept->fresh()->qty_returned);
        $this->assertSame(0.0, (float) $removed->fresh()->qty_returned);
        $this->assertSame(6.0, $removed->fresh()->remaining());
    }

    public function test_qty_melebihi_sisa_ditolak_tanpa_mengubah_stok(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $line = $this->lineFor($shipment, 'INDF-0001');
        $before = $this->onHand('A', 'INDF-0001');

        $this->actingAs($this->managerA)->post(route('admin.stock-transfer-returns.store'), [
            'shipment_id' => $shipment->id,
            'lines' => [$line->id => ['qty' => 11]],
        ])->assertRedirect()->assertSessionHas('error', fn (string $message) => str_contains($message, 'exceeds remaining'));

        $this->assertSame(0, StockTransferReturn::count());
        $this->assertSame(0.0, (float) $line->fresh()->qty_returned);
        $this->assertSame($before, $this->onHand('A', 'INDF-0001'));
    }

    public function test_semua_baris_dihapus_ditolak(): void
    {
        $shipment = $this->postedShipment('A', 'B');

        $this->actingAs($this->managerA)->post(route('admin.stock-transfer-returns.store'), [
            'shipment_id' => $shipment->id,
        ])->assertRedirect()->assertSessionHasErrors('lines');

        $this->assertSame(0, StockTransferReturn::count());
    }

    public function test_notes_lebih_dari_254_karakter_ditolak(): void
    {
        $shipment = $this->postedShipment('A', 'B');

        $this->actingAs($this->managerA)->post(route('admin.stock-transfer-returns.store'), [
            'shipment_id' => $shipment->id,
            'note' => str_repeat('x', 255),
            'lines' => [$this->lineFor($shipment, 'INDF-0001')->id => ['qty' => 1]],
        ])->assertSessionHasErrors('note');

        $this->assertSame(0, StockTransferReturn::count());
    }

    public function test_sm_penerima_dan_sm_gerai_lain_ditolak_sm_pengirim_boleh(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $line = $this->lineFor($shipment, 'INDF-0001');
        $payload = ['shipment_id' => $shipment->id, 'lines' => [$line->id => ['qty' => 1]]];
        $managerC = $this->makeUser('managerc', User::ROLE_STORE_MANAGER, 'C');

        foreach ([$this->managerB, $managerC] as $manager) {
            $this->actingAs($manager)->get(route('admin.stock-transfer-returns.create', ['shipment' => $shipment->id]))->assertForbidden();
            $this->actingAs($manager)->post(route('admin.stock-transfer-returns.store'), $payload)->assertForbidden();
        }

        $this->assertSame(0, StockTransferReturn::count());

        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-returns.create', ['shipment' => $shipment->id]))->assertOk();
        $this->actingAs($this->managerA)->post(route('admin.stock-transfer-returns.store'), $payload)->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, StockTransferReturn::count());
    }

    public function test_tombol_return_di_halaman_shipment(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $url = route('admin.stock-transfer-returns.create', ['shipment' => $shipment->id]);

        foreach ([$this->managerA, $this->owner] as $user) {
            $this->actingAs($user)->get(route('admin.stock-transfer-shipments.index'))->assertOk()->assertSee($url, false);
            $this->actingAs($user)->get(route('admin.stock-transfer-shipments.show', $shipment))->assertOk()->assertSee($url, false);
        }

        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-shipments.index'))->assertOk()->assertDontSee($url, false);
        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-shipments.show', $shipment))->assertOk()->assertDontSee($url, false);

        (new StockTransferService)->returnToSender($shipment, [
            $this->lineFor($shipment, 'INDF-0001')->id => 10, $this->lineFor($shipment, 'MINU-0002')->id => 6,
        ], $this->owner);

        $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.index'))->assertDontSee($url, false);
        $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.show', $shipment))->assertDontSee($url, false);
    }

    public function test_show_shipment_menampilkan_kolom_returned_dan_daftar_return(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $return = (new StockTransferService)->returnToSender($shipment, [$this->lineFor($shipment, 'INDF-0001')->id => 3], $this->owner);

        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-shipments.show', $shipment))
            ->assertOk()->assertSee('Returned')->assertSee('Returns')->assertSee($return->number);
    }

    public function test_menu_menampilkan_stock_transfer_return(): void
    {
        $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.index'))
            ->assertOk()->assertSee('href="'.route('admin.stock-transfer-returns.index').'"', false);
    }

    public function test_return_dan_shipment_perusahaan_lain_404(): void
    {
        $otherCompany = CompanyDetail::create(['name' => 'Other Co']);
        $otherStore = Store::create(['company_id' => $otherCompany->id, 'code' => 'XSTR', 'name' => 'Foreign', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $foreignShipment = StockTransferShipment::create([
            'company_id' => $otherCompany->id, 'from_store_id' => $otherStore->id, 'to_store_id' => $otherStore->id,
            'status' => StockTransferShipment::STATUS_POSTED, 'created_by' => $this->owner->id,
        ]);
        $foreignReturn = StockTransferReturn::create([
            'company_id' => $otherCompany->id, 'shipment_id' => $foreignShipment->id, 'number' => 'RET-X-1',
            'store_id' => $otherStore->id, 'returned_at' => now(), 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->get(route('admin.stock-transfer-returns.show', $foreignReturn))->assertNotFound();
        $this->actingAs($this->owner)->post(route('admin.stock-transfer-returns.retry', $foreignReturn))->assertNotFound();
        $this->actingAs($this->owner)->get(route('admin.stock-transfer-returns.create', ['shipment' => $foreignShipment->id]))->assertNotFound();
    }

    public function test_index_dan_show_docnum_tanpa_docentry_dan_visibilitas_penerima(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $return = (new StockTransferService)->returnToSender($shipment, [$this->lineFor($shipment, 'INDF-0001')->id => 3], $this->owner);
        $return->forceFill(['sap_post_status' => StockTransferReturn::SAP_POSTED, 'sap_doc_num' => 7731, 'sap_doc_entry' => 98765])->save();
        $managerC = $this->makeUser('managerc', User::ROLE_STORE_MANAGER, 'C');

        foreach ([$this->managerA, $this->managerB] as $manager) {
            $this->actingAs($manager)->get(route('admin.stock-transfer-returns.index'))
                ->assertOk()->assertSee($return->number)->assertSee('67 7731')->assertDontSee('98765');
            $this->actingAs($manager)->get(route('admin.stock-transfer-returns.show', $return))
                ->assertOk()->assertSee('INDF-0001')->assertSee('67 7731')->assertDontSee('98765');
        }

        $this->actingAs($managerC)->get(route('admin.stock-transfer-returns.show', $return))->assertForbidden();
        $this->actingAs($managerC)->get(route('admin.stock-transfer-returns.index'))->assertDontSee($return->number);
    }

    public function test_retry_hanya_owner_untuk_null_pending_dan_failed(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $shipment->forceFill(['sap_post_status' => StockTransferShipment::SAP_POSTED])->save();

        foreach ([null, StockTransferReturn::SAP_PENDING, StockTransferReturn::SAP_FAILED] as $sapStatus) {
            $return = (new StockTransferService)->returnToSender($shipment, [$this->lineFor($shipment, 'INDF-0001')->id => 1], $this->owner);
            $return->forceFill(['sap_post_status' => $sapStatus])->save();

            $this->actingAs($this->managerA)->post(route('admin.stock-transfer-returns.retry', $return))->assertForbidden();
            $this->actingAs($this->owner)->get(route('admin.stock-transfer-returns.index'))->assertSee('Retry');
            $this->actingAs($this->managerA)->get(route('admin.stock-transfer-returns.index'))->assertDontSee('Retry');

            $this->actingAs($this->owner)->post(route('admin.stock-transfer-returns.retry', $return))
                ->assertRedirect()->assertSessionHas('success');
            $this->assertSame(StockTransferReturn::SAP_PENDING, $return->fresh()->sap_post_status);
            Queue::assertPushed(PostStockTransferToSap::class, fn (PostStockTransferToSap $job) => $job->documentId === $return->id);

            $return->forceFill(['sap_post_status' => StockTransferReturn::SAP_POSTED])->save();
            $this->actingAs($this->owner)->post(route('admin.stock-transfer-returns.retry', $return))->assertStatus(422);
        }
    }

    public function test_retry_menunggu_shipment_dan_integrasi_mati_jujur(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        $shipment->forceFill(['sap_post_status' => StockTransferShipment::SAP_FAILED])->save();
        $return = (new StockTransferService)->returnToSender($shipment, [$this->lineFor($shipment, 'INDF-0001')->id => 1], $this->owner);
        $return->forceFill(['sap_post_status' => null])->save();

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-returns.retry', $return))
            ->assertRedirect()->assertSessionHas('error', "Return waits for shipment {$shipment->number} to be posted to SAP.");
        $this->assertSame(StockTransferReturn::SAP_PENDING, $return->fresh()->sap_post_status);

        $return->forceFill(['sap_post_status' => StockTransferReturn::SAP_FAILED])->save();
        $this->company->update(['sap_enabled' => false]);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-returns.retry', $return))
            ->assertRedirect()->assertSessionHas('error', 'SAP integration is inactive.')->assertSessionMissing('success');
        $this->assertSame(StockTransferReturn::SAP_FAILED, $return->fresh()->sap_post_status);
    }

    public function test_pemilih_receipt_mengecualikan_shipment_yang_sudah_habis_dikembalikan(): void
    {
        $returned = $this->postedShipment('A', 'B', ['INDF-0001' => 5]);
        (new StockTransferService)->returnToSender($returned, [$this->lineFor($returned, 'INDF-0001')->id => 5], $this->owner);
        $open = $this->postedShipment('A', 'B', ['INDF-0001' => 5]);

        $response = $this->actingAs($this->managerB)->get(route('admin.stock-transfer-receipts.create'))
            ->assertOk()->assertSee($open->number)->assertDontSee($returned->number);

        $this->assertCount(1, $response->viewData('receivable'));
    }

    public function test_form_receipt_menampilkan_kolom_returned(): void
    {
        $shipment = $this->postedShipment('A', 'B');
        (new StockTransferService)->returnToSender($shipment, [$this->lineFor($shipment, 'INDF-0001')->id => 4], $this->owner);

        $this->actingAs($this->managerB)->get(route('admin.stock-transfer-receipts.create', ['shipment' => $shipment->id]))
            ->assertOk()->assertSee('Already Returned')->assertSee('max="6"', false);
    }
}
