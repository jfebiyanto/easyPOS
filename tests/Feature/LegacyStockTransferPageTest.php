<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegacyStockTransferPageTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    private StockTransfer $transfer;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $company = CompanyDetail::create(['name' => 'Test Co']);
        $this->storeA = Store::create(['company_id' => $company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $this->storeB = Store::create(['company_id' => $company->id, 'code' => 'STR002', 'name' => 'Gerai B', 'pic_name' => 'PIC', 'currency' => 'IDR']);

        $this->owner = User::create([
            'company_id' => $company->id, 'store_id' => $this->storeA->id, 'name' => 'Owner', 'username' => 'owner1',
            'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password',
        ]);
        $this->manager = User::create([
            'company_id' => $company->id, 'store_id' => $this->storeA->id, 'name' => 'Manager', 'username' => 'manager1',
            'role' => User::ROLE_STORE_MANAGER, 'is_active' => true, 'password' => 'password',
        ]);

        $unit = Unit::create(['company_id' => $company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $company->id, 'code' => 'BEV', 'name' => 'Minuman', 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id,
            'sku' => 'SKU-1', 'name' => 'Aqua', 'base_price' => 4000, 'track_stock' => true, 'is_active' => true,
        ]);

        $this->transfer = StockTransfer::create([
            'company_id' => $company->id, 'number' => 'TRF-OLD-001', 'from_store_id' => $this->storeA->id,
            'to_store_id' => $this->storeB->id, 'status' => StockTransfer::STATUS_SHIPPED,
        ]);
        $this->transfer->lines()->create(['product_id' => $product->id, 'qty_requested' => 4, 'qty_shipped' => 4]);
    }

    public function test_owner_dan_store_manager_dapat_melihat_halaman_legacy_tanpa_tombol_aksi(): void
    {
        foreach ([$this->owner, $this->manager] as $user) {
            $this->actingAs($user)
                ->get(route('admin.stock-transfers.index'))
                ->assertOk()
                ->assertSee('Legacy Stock Transfers')
                ->assertSee('Read-only')
                ->assertSee('TRF-OLD-001')
                ->assertSee('STR001')
                ->assertSee('STR002')
                ->assertSee('Shipped')
                ->assertDontSee('Dikirim')
                ->assertDontSee('Create Transfer')
                ->assertDontSee('>Ship<', false)
                ->assertDontSee('>Receive<', false)
                ->assertDontSee('>Cancel<', false)
                ->assertDontSee('/stock-transfers/', false);
        }
    }

    public function test_rute_tulis_alur_lama_sudah_tidak_ada(): void
    {
        foreach (['create', 'store', 'ship', 'receive-form', 'receive', 'cancel'] as $suffix) {
            $this->assertFalse(Route::has('admin.stock-transfers.'.$suffix), $suffix);
        }

        $id = $this->transfer->id;
        $this->actingAs($this->owner);

        $this->post("/admin/stock-transfers/{$id}/ship")->assertStatus(404);
        $this->post("/admin/stock-transfers/{$id}/cancel")->assertStatus(404);
        $this->post("/admin/stock-transfers/{$id}/receive")->assertStatus(404);
        $this->get("/admin/stock-transfers/{$id}/receive")->assertStatus(404);
        $this->get('/admin/stock-transfers/create')->assertStatus(404);
        $this->post('/admin/stock-transfers')->assertStatus(405);
    }
}
