<?php

namespace Tests\Feature;

use App\Jobs\PostStockTransferToSap;
use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockTransferShipment;
use App\Models\StockTransferShipmentLine;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockTransferShipmentPageTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    /** @var array<string, Store> */
    private array $stores = [];

    /** @var array<string, Warehouse> */
    private array $warehouses = [];

    private User $owner;

    private User $managerA;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co', 'allow_negative_stock' => false, 'sap_enabled' => true, 'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1', 'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager', 'sap_password' => 'secret',
        ]);

        foreach (['A', 'B', 'C', 'D'] as $i => $key) {
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

        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum']);
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->product = Product::create([
            'company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id,
            'sku' => 'INDF-0001', 'name' => 'Indomie', 'base_price' => 4000, 'track_stock' => true, 'is_active' => true,
        ]);

        WarehouseStockService::record($this->warehouses['A'], $this->product->id, WarehouseStockLedger::TYPE_OPENING, 10, ['created_by' => null]);
    }

    private function makeUser(string $username, string $role, string $storeKey): User
    {
        return User::create([
            'company_id' => $this->company->id, 'store_id' => $this->stores[$storeKey]->id, 'name' => ucfirst($username),
            'username' => $username, 'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function draft(string $from, string $to, float $qty = 5): StockTransferShipment
    {
        $shipment = StockTransferShipment::create([
            'company_id' => $this->company->id, 'from_store_id' => $this->stores[$from]->id,
            'to_store_id' => $this->stores[$to]->id, 'status' => StockTransferShipment::STATUS_DRAFT,
            'created_by' => $this->owner->id,
        ]);
        StockTransferShipmentLine::create(['shipment_id' => $shipment->id, 'product_id' => $this->product->id, 'qty' => $qty, 'qty_received' => 0]);

        return $shipment;
    }

    private function onHand(string $warehouseKey): float
    {
        return (float) WarehouseStock::where('warehouse_id', $this->warehouses[$warehouseKey]->id)
            ->where('product_id', $this->product->id)->value('on_hand');
    }

    public function test_notes_lebih_dari_254_karakter_ditolak_karena_menjadi_comments_sap(): void
    {
        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.store'), [
            'to_store_id' => $this->stores['B']->id,
            'note' => str_repeat('x', 255),
            'lines' => [['product_id' => $this->product->id, 'qty' => 4]],
        ])->assertSessionHasErrors('note');

        $this->assertSame(0, StockTransferShipment::count());
    }

    public function test_owner_membuat_draft_lalu_post_memindahkan_stok(): void
    {
        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.store'), [
            'to_store_id' => $this->stores['B']->id,
            'note' => 'Restock',
            'lines' => [['product_id' => $this->product->id, 'qty' => 4], ['product_id' => '', 'qty' => '']],
        ])->assertRedirect(route('admin.stock-transfer-shipments.index'));

        $shipment = StockTransferShipment::firstOrFail();
        $this->assertSame(StockTransferShipment::STATUS_DRAFT, $shipment->status);
        $this->assertSame($this->stores['A']->id, $shipment->from_store_id);
        $this->assertCount(1, $shipment->lines);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.post', $shipment))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(StockTransferShipment::STATUS_POSTED, $shipment->fresh()->status);
        $this->assertSame(6.0, $this->onHand('A'));
    }

    public function test_draft_bisa_diubah_dan_dihapus(): void
    {
        $shipment = $this->draft('A', 'B', 5);

        $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.edit', $shipment))->assertOk();

        $this->actingAs($this->owner)->put(route('admin.stock-transfer-shipments.update', $shipment), [
            'to_store_id' => $this->stores['C']->id,
            'note' => 'Diubah',
            'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
        ])->assertRedirect(route('admin.stock-transfer-shipments.index'));

        $shipment->refresh();
        $this->assertSame($this->stores['C']->id, $shipment->to_store_id);
        $this->assertSame(2.0, (float) $shipment->lines()->first()->qty);

        $this->actingAs($this->owner)->delete(route('admin.stock-transfer-shipments.destroy', $shipment))
            ->assertRedirect(route('admin.stock-transfer-shipments.index'));
        $this->assertDatabaseMissing('stock_transfer_shipments', ['id' => $shipment->id]);
    }

    public function test_shipment_posted_tidak_bisa_diubah_atau_dihapus(): void
    {
        $shipment = $this->draft('A', 'B', 5);
        $shipment->forceFill(['status' => StockTransferShipment::STATUS_POSTED, 'number' => 'STS-1'])->save();

        $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.edit', $shipment))->assertStatus(422);
        $this->actingAs($this->owner)->put(route('admin.stock-transfer-shipments.update', $shipment), [
            'to_store_id' => $this->stores['C']->id, 'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertStatus(422);
        $this->actingAs($this->owner)->delete(route('admin.stock-transfer-shipments.destroy', $shipment))->assertStatus(422);

        $this->assertDatabaseHas('stock_transfer_shipments', ['id' => $shipment->id, 'to_store_id' => $this->stores['B']->id]);
    }

    public function test_post_ditolak_menampilkan_pesan_service(): void
    {
        $shipment = $this->draft('A', 'B', 11);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.post', $shipment))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Insufficient stock for INDF-0001'));

        $this->assertSame(StockTransferShipment::STATUS_DRAFT, $shipment->fresh()->status);
        $this->assertSame(10.0, $this->onHand('A'));
    }

    public function test_store_manager_hanya_mengakses_shipment_gerainya(): void
    {
        $visible = $this->draft('A', 'B');
        $hidden = $this->draft('C', 'D');

        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-shipments.show', $visible))->assertOk();
        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-shipments.show', $hidden))->assertForbidden();
        $this->actingAs($this->managerA)->post(route('admin.stock-transfer-shipments.post', $hidden))->assertForbidden();
        $this->actingAs($this->managerA)->delete(route('admin.stock-transfer-shipments.destroy', $hidden))->assertForbidden();
        $this->assertSame(StockTransferShipment::STATUS_DRAFT, $hidden->fresh()->status);
    }

    public function test_index_store_manager_tidak_memuat_shipment_gerai_lain(): void
    {
        $this->draft('A', 'B');
        $this->draft('C', 'D');

        $response = $this->actingAs($this->managerA)->get(route('admin.stock-transfer-shipments.index'))->assertOk();

        $this->assertCount(1, $response->viewData('shipments'));
    }

    public function test_store_manager_tidak_bisa_membuat_dari_gerai_lain(): void
    {
        $this->actingAs($this->managerA)->post(route('admin.stock-transfer-shipments.store'), [
            'from_store_id' => $this->stores['C']->id,
            'to_store_id' => $this->stores['D']->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertRedirect();

        $shipment = StockTransferShipment::firstOrFail();
        $this->assertSame($this->stores['A']->id, $shipment->from_store_id);
    }

    public function test_tujuan_tidak_boleh_sama_dengan_asal(): void
    {
        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.store'), [
            'to_store_id' => $this->stores['A']->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertSessionHasErrors('to_store_id');

        $this->assertSame(0, StockTransferShipment::count());
    }

    public function test_retry_ditolak_untuk_store_manager(): void
    {
        $shipment = $this->draft('A', 'B');
        $shipment->forceFill(['status' => StockTransferShipment::STATUS_POSTED, 'sap_post_status' => StockTransferShipment::SAP_FAILED])->save();

        $this->actingAs($this->managerA)->post(route('admin.stock-transfer-shipments.retry', $shipment))->assertForbidden();

        $this->assertSame(StockTransferShipment::SAP_FAILED, $shipment->fresh()->sap_post_status);
    }

    public function test_owner_retry_shipment_failed_mengantrekan_ulang(): void
    {
        $shipment = $this->draft('A', 'B');
        $shipment->forceFill([
            'status' => StockTransferShipment::STATUS_POSTED, 'number' => 'STS-1',
            'sap_post_status' => StockTransferShipment::SAP_FAILED, 'sap_post_error' => 'boom',
        ])->save();

        $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.index'))->assertOk()->assertSee('Retry');
        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-shipments.index'))->assertOk()->assertDontSee('Retry');

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.retry', $shipment))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(StockTransferShipment::SAP_PENDING, $shipment->fresh()->sap_post_status);
        Queue::assertPushed(PostStockTransferToSap::class);
    }

    public function test_retry_ditolak_untuk_sap_posted_dan_draft(): void
    {
        $postedToSap = $this->draft('A', 'B');
        $postedToSap->forceFill(['status' => StockTransferShipment::STATUS_POSTED, 'number' => 'STS-1', 'sap_post_status' => StockTransferShipment::SAP_POSTED])->save();
        $draft = $this->draft('A', 'B');

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.retry', $postedToSap))->assertStatus(422);
        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.retry', $draft))->assertStatus(422);
        Queue::assertNotPushed(PostStockTransferToSap::class);
    }

    public function test_owner_retry_shipment_null_dan_pending_macet_mengantrekan_ulang(): void
    {
        foreach ([null, StockTransferShipment::SAP_PENDING] as $i => $sapStatus) {
            $shipment = $this->draft('A', 'B');
            $shipment->forceFill(['status' => StockTransferShipment::STATUS_POSTED, 'number' => "STS-{$i}", 'sap_post_status' => $sapStatus])->save();

            $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.index'))->assertOk()->assertSee('Retry');

            $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.retry', $shipment))
                ->assertRedirect()->assertSessionHas('success');

            $this->assertSame(StockTransferShipment::SAP_PENDING, $shipment->fresh()->sap_post_status);
            Queue::assertPushed(PostStockTransferToSap::class, fn (PostStockTransferToSap $job) => $job->documentId === $shipment->id);
            $shipment->delete();
        }
    }

    public function test_retry_shipment_saat_integrasi_mati_menampilkan_error(): void
    {
        $shipment = $this->draft('A', 'B');
        $shipment->forceFill(['status' => StockTransferShipment::STATUS_POSTED, 'number' => 'STS-1', 'sap_post_status' => StockTransferShipment::SAP_FAILED])->save();
        $this->company->update(['sap_enabled' => false]);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.retry', $shipment))
            ->assertRedirect()->assertSessionHas('error', 'SAP integration is inactive.')->assertSessionMissing('success');

        $this->assertSame(StockTransferShipment::SAP_FAILED, $shipment->fresh()->sap_post_status);
        Queue::assertNotPushed(PostStockTransferToSap::class);
    }

    public function test_owner_memilih_gerai_asal_lain_lalu_post_memindahkan_stok_gerai_itu(): void
    {
        WarehouseStockService::record($this->warehouses['B'], $this->product->id, WarehouseStockLedger::TYPE_OPENING, 8, ['created_by' => null]);

        $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.create'))
            ->assertOk()->assertSee('name="from_store_id"', false);
        $this->actingAs($this->managerA)->get(route('admin.stock-transfer-shipments.create'))
            ->assertOk()->assertDontSee('name="from_store_id"', false);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.store'), [
            'from_store_id' => $this->stores['B']->id,
            'to_store_id' => $this->stores['C']->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 3]],
        ])->assertRedirect(route('admin.stock-transfer-shipments.index'));

        $shipment = StockTransferShipment::firstOrFail();
        $this->assertSame($this->stores['B']->id, $shipment->from_store_id);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.post', $shipment))->assertSessionHas('success');

        $this->assertSame(5.0, $this->onHand('B'));
        $this->assertSame(10.0, $this->onHand('A'));
    }

    public function test_owner_tidak_bisa_memilih_gerai_asal_perusahaan_lain(): void
    {
        $otherCompany = CompanyDetail::create(['name' => 'Other Co']);
        $foreign = Store::create(['company_id' => $otherCompany->id, 'code' => 'XSTR', 'name' => 'Foreign', 'pic_name' => 'PIC', 'currency' => 'IDR']);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.store'), [
            'from_store_id' => $foreign->id,
            'to_store_id' => $this->stores['B']->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, StockTransferShipment::count());
    }

    public function test_update_tanpa_baris_valid_mempertahankan_baris_draft(): void
    {
        $shipment = $this->draft('A', 'B', 5);

        $this->actingAs($this->owner)->put(route('admin.stock-transfer-shipments.update', $shipment), [
            'to_store_id' => $this->stores['C']->id,
            'lines' => [['product_id' => '', 'qty' => '']],
        ])->assertRedirect()->assertSessionHas('error', 'There are no valid lines to save.');

        $shipment->refresh();
        $this->assertSame($this->stores['B']->id, $shipment->to_store_id);
        $this->assertSame([5.0], $shipment->lines()->pluck('qty')->map(fn ($qty) => (float) $qty)->all());
    }

    public function test_store_tanpa_baris_valid_tidak_membuat_shipment(): void
    {
        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.store'), [
            'to_store_id' => $this->stores['B']->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 0]],
        ])->assertRedirect()->assertSessionHas('error', 'There are no valid lines to save.');

        $this->assertSame(0, StockTransferShipment::count());
    }

    public function test_produk_tanpa_track_stock_ditolak(): void
    {
        $untracked = Product::create([
            'company_id' => $this->company->id, 'product_group_id' => $this->product->product_group_id, 'unit_id' => $this->product->unit_id,
            'sku' => 'SVC-0001', 'name' => 'Service', 'base_price' => 1000, 'track_stock' => false, 'is_active' => true,
        ]);

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.store'), [
            'to_store_id' => $this->stores['B']->id,
            'lines' => [['product_id' => $untracked->id, 'qty' => 1]],
        ])->assertSessionHasErrors('lines.0.product_id');

        $this->assertSame(0, StockTransferShipment::count());
    }

    public function test_gerai_penerima_tidak_melihat_draft_pengirim(): void
    {
        $managerB = $this->makeUser('managerb', User::ROLE_STORE_MANAGER, 'B');
        $shipment = $this->draft('A', 'B');

        $this->assertCount(0, $this->actingAs($managerB)->get(route('admin.stock-transfer-shipments.index'))->assertOk()->viewData('shipments'));
        $this->actingAs($managerB)->get(route('admin.stock-transfer-shipments.show', $shipment))->assertForbidden();

        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.post', $shipment))->assertSessionHas('success');

        $this->assertCount(1, $this->actingAs($managerB)->get(route('admin.stock-transfer-shipments.index'))->assertOk()->viewData('shipments'));
        $this->actingAs($managerB)->get(route('admin.stock-transfer-shipments.show', $shipment))->assertOk();
    }

    public function test_gerai_penerima_ditolak_mengubah_memposting_dan_menghapus(): void
    {
        $managerB = $this->makeUser('managerb', User::ROLE_STORE_MANAGER, 'B');
        $shipment = $this->draft('A', 'B');

        $this->actingAs($managerB)->get(route('admin.stock-transfer-shipments.edit', $shipment))->assertForbidden();
        $this->actingAs($managerB)->put(route('admin.stock-transfer-shipments.update', $shipment), [
            'to_store_id' => $this->stores['C']->id, 'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertForbidden();
        $this->actingAs($managerB)->post(route('admin.stock-transfer-shipments.post', $shipment))->assertForbidden();
        $this->actingAs($managerB)->delete(route('admin.stock-transfer-shipments.destroy', $shipment))->assertForbidden();

        $shipment->refresh();
        $this->assertSame(StockTransferShipment::STATUS_DRAFT, $shipment->status);
        $this->assertSame($this->stores['B']->id, $shipment->to_store_id);
    }

    public function test_saldo_stok_menampilkan_gudang_in_transit(): void
    {
        $shipment = $this->draft('A', 'B', 4);
        $this->actingAs($this->owner)->post(route('admin.stock-transfer-shipments.post', $shipment))->assertSessionHas('success');

        $response = $this->actingAs($this->owner)->get(route('admin.stock.index', ['store' => $this->stores['A']->id]))
            ->assertOk()->assertSee('In-Transit Warehouse G.000.IT');

        $this->assertSame(4.0, (float) $response->viewData('transitBalances')[$this->product->id]->on_hand);

        $mutations = $this->actingAs($this->owner)
            ->get(route('admin.stock.mutations', ['product' => $this->product, 'store' => $this->stores['A']->id, 'transit' => 1]))
            ->assertOk()->assertSee('G.000.IT');
        $this->assertSame(4.0, (float) $mutations->viewData('stock')->on_hand);

        $this->warehouses['D']->update(['transit_warehouse_code' => null]);
        $this->actingAs($this->owner)->get(route('admin.stock.index', ['store' => $this->stores['D']->id]))
            ->assertOk()->assertDontSee('In-Transit Warehouse');
    }

    public function test_store_dengan_dokumen_transfer_tidak_bisa_dihapus(): void
    {
        $this->draft('C', 'D');

        foreach (['C', 'D'] as $key) {
            $this->actingAs($this->owner)->delete(route('admin.stores.destroy', $this->stores[$key]))
                ->assertRedirect()->assertSessionHas('error', 'Store has stock transfer documents and cannot be deleted.');
            $this->assertDatabaseHas('stores', ['id' => $this->stores[$key]->id]);
        }

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/stores/{$this->stores['C']->id}")
            ->assertStatus(422)->assertJson(['message' => 'Store has stock transfer documents and cannot be deleted.']);
        $this->assertDatabaseHas('stores', ['id' => $this->stores['C']->id]);
    }

    public function test_index_menampilkan_docnum_tanpa_docentry(): void
    {
        $shipment = $this->draft('A', 'B');
        $shipment->forceFill([
            'status' => StockTransferShipment::STATUS_POSTED, 'number' => 'STS-1', 'sap_post_status' => StockTransferShipment::SAP_POSTED,
            'sap_doc_num' => 7788, 'sap_doc_entry' => 99123,
        ])->save();

        $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.index'))
            ->assertOk()
            ->assertSee('67 7788')
            ->assertDontSee('99123')
            ->assertSee('Legacy transfers')
            ->assertSee('In Transit');
    }

    public function test_menu_menampilkan_stock_transfer_shipment(): void
    {
        $this->actingAs($this->owner)->get(route('admin.stock-transfer-shipments.index'))
            ->assertSee('Stock Transfer Shipment');
    }
}
