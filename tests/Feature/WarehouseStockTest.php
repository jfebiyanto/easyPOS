<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseStockTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $owner;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);
        $this->store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);
        $this->owner = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum']);
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->product = Product::create([
            'company_id' => $this->company->id,
            'product_group_id' => $group->id,
            'unit_id' => $unit->id,
            'sku' => 'SKU-1',
            'name' => 'Aqua',
            'base_price' => 10000,
            'track_stock' => true,
            'is_active' => true,
        ]);
    }

    public function test_halaman_saldo_stok_membaca_saldo_warehouse_milik_gerai(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id, 'code' => 'WH-01', 'name' => 'Gudang Satu']);
        $this->store->update(['warehouse_id' => $warehouse->id]);
        WarehouseStock::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $this->product->id,
            'on_hand' => 21,
            'committed' => 2,
            'on_order' => 5,
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.stock.index', ['store' => $this->store->id]))
            ->assertOk()
            ->assertSee('WH-01 · Gudang Satu')
            ->assertSeeInOrder(['SKU-1', '21', '2', '5']);
    }

    public function test_saldo_stok_menampilkan_selisih_terhadap_saldo_sap(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->store->update(['warehouse_id' => $warehouse->id]);
        WarehouseStock::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $this->product->id,
            'on_hand' => 158,
            'sap_on_hand' => 160,
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.stock.index', ['store' => $this->store->id]))
            ->assertOk()
            ->assertSeeInOrder(['SKU-1', '158', '160', '-2']);
    }

    public function test_riwayat_mutasi_menampilkan_dokumen_sap_warehouse_gerai(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->store->update(['warehouse_id' => $warehouse->id]);
        WarehouseStockService::record($warehouse, $this->product->id, WarehouseStockLedger::TYPE_GOODS_ISSUE, -2, [
            'attributes' => ['sap_trans_seq' => 7, 'sap_trans_type' => 60, 'sap_doc_entry' => 5, 'sap_doc_num' => '2600002', 'sap_doc_line' => 0],
        ]);
        WarehouseStockService::record($otherWarehouse, $this->product->id, WarehouseStockLedger::TYPE_PURCHASE, 5, [
            'attributes' => ['sap_trans_seq' => 8, 'sap_doc_num' => '2609999'],
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.stock.mutations', ['product' => $this->product, 'store' => $this->store->id]))
            ->assertOk()
            ->assertSeeInOrder(['Goods Issue 2600002', '-2'])
            ->assertDontSee('2609999');
    }

    public function test_gerai_tanpa_warehouse_menampilkan_peringatan(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.stock.index', ['store' => $this->store->id]))
            ->assertOk()
            ->assertSee('belum dihubungkan ke warehouse');
    }

    public function test_pergerakan_dicatat_di_buku_besar_dan_memperbarui_saldo(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        WarehouseStockService::record($warehouse, $this->product->id, WarehouseStockLedger::TYPE_OPENING, 10, ['created_by' => $this->owner->id]);
        $ledger = WarehouseStockService::record($warehouse, $this->product->id, WarehouseStockLedger::TYPE_SALE, -3, ['ref_type' => 'sale']);

        $this->assertSame('7.000', $ledger->balance_after);
        $this->assertSame('7.000', WarehouseStock::where('warehouse_id', $warehouse->id)->value('on_hand'));
        $this->assertSame(2, WarehouseStockLedger::where('warehouse_id', $warehouse->id)->count());
    }
}
