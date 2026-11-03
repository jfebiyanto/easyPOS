<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CompanyDetail;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);
        $this->store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'STR001',
            'name' => 'Store Satu',
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
    }

    public function test_kasir_tidak_bisa_mengakses_backoffice(): void
    {
        $cashier = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Kasir',
            'username' => 'kasir1',
            'role' => User::ROLE_CASHIER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->actingAs($cashier)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_owner_dan_manager_bisa_mengakses_backoffice(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.dashboard'))
            ->assertOk();

        $manager = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Manager',
            'username' => 'manager1',
            'role' => User::ROLE_STORE_MANAGER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->actingAs($manager)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_store_aktif_dapat_diganti_dari_top_bar_dan_kembali_ke_halaman_asal(): void
    {
        $other = Store::create(['company_id' => $this->company->id, 'code' => 'STR002', 'name' => 'Store Dua', 'pic_name' => 'PIC', 'currency' => 'IDR']);

        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Ganti Store Aktif')
            ->assertSee(route('admin.stores.switch', $other), false);

        $this->actingAs($this->owner)
            ->from(route('admin.dashboard'))
            ->post(route('admin.stores.switch', $other))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('active_store_id', $other->id);
    }

    public function test_manager_hanya_melihat_info_store_aktif_tanpa_pilihan_ganti_store(): void
    {
        Store::create(['company_id' => $this->company->id, 'code' => 'STR002', 'name' => 'Store Dua', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $manager = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Manager', 'username' => 'manager1', 'role' => User::ROLE_STORE_MANAGER, 'is_active' => true, 'password' => 'password']);

        $this->actingAs($manager)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('STR001 · Store Satu')
            ->assertDontSee('Ganti Store Aktif')
            ->assertDontSee('Edit Store Ini');
    }

    public function test_produk_diurutkan_berdasarkan_sku_di_daftar_produk_dan_penyesuaian_stok(): void
    {
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'BEV', 'name' => 'Minuman', 'is_active' => true]);
        foreach ([['SKU-0002', 'Aqua'], ['SKU-0001', 'Zebra Cola']] as [$sku, $name]) {
            Product::create(['company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id, 'sku' => $sku, 'name' => $name, 'base_price' => 1000, 'track_stock' => true, 'is_active' => true]);
        }

        $this->actingAs($this->owner)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Zebra Cola', 'Aqua']);

        $this->actingAs($this->owner)->get(route('admin.stock-adjustments.create'))
            ->assertOk()
            ->assertSeeInOrder(['SKU-0001 · Zebra Cola', 'SKU-0002 · Aqua']);
    }

    public function test_produk_baru_masuk_pricelist_default_store(): void
    {
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'BEV', 'name' => 'Minuman', 'is_active' => true]);

        $priceList = PriceList::create([
            'company_id' => $this->company->id,
            'code' => 'DEFAULT',
            'name' => 'Default',
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->store->priceLists()->sync([$priceList->id => ['is_default' => true]]);

        $this->actingAs($this->owner)
            ->post(route('admin.products.store'), [
                'product_group_id' => $group->id,
                'unit_id' => $unit->id,
                'sku' => 'SKU-TEST-1',
                'name' => 'Produk Uji',
                'base_price' => 5000,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.products.index'));

        $product = Product::where('sku', 'SKU-TEST-1')->first();
        $this->assertNotNull($product);
        $this->assertSame($this->company->id, $product->company_id);

        $this->assertDatabaseHas('price_list_items', [
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'min_qty' => 1,
            'price' => 5000,
        ]);
    }

    public function test_minimal_satu_owner_aktif_dijaga(): void
    {
        $this->actingAs($this->owner)
            ->put(route('admin.users.update', $this->owner), [
                'name' => 'Owner',
                'username' => 'owner1',
                'role' => User::ROLE_CASHIER,
                'is_active' => '1',
            ])
            ->assertStatus(422);
    }

    public function test_semua_halaman_admin_utama_dapat_dirender(): void
    {
        $this->actingAs($this->owner);

        $routes = [
            'admin.dashboard',
            'admin.company.edit',
            'admin.stores.index',
            'admin.users.index',
            'admin.units.index',
            'admin.groups.index',
            'admin.brands.index',
            'admin.products.index',
            'admin.price-lists.index',
            'admin.promotions.index',
            'admin.currencies.index',
            'admin.payment-types.index',
            'admin.numberings.index',
            'admin.logs.index',
            'admin.stock.index',
            'admin.stock-adjustments.index',
            'admin.stock-adjustments.create',
            'admin.stock-transfers.index',
            'admin.stock-transfers.create',
            'admin.customers.index',
            'admin.member-levels.index',
            'admin.reports.index',
            'admin.eod.index',
            'admin.stock-review.index',
            'admin.deliveries.index',
        ];

        foreach ($routes as $name) {
            $this->get(route($name))->assertOk();
        }

        $createRoutes = [
            'admin.stores.create',
            'admin.users.create',
            'admin.units.create',
            'admin.groups.create',
            'admin.brands.create',
            'admin.products.create',
            'admin.price-lists.create',
            'admin.promotions.create',
            'admin.currencies.create',
            'admin.payment-types.create',
            'admin.customers.create',
            'admin.member-levels.create',
        ];

        foreach ($createRoutes as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_sidebar_hanya_membuka_grup_halaman_aktif(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('<div class="nav-group " data-section="produk">', false)
            ->assertSee('<div class="nav-group collapsed" data-section="konfigurasi">', false)
            ->assertSee('<div class="nav-group collapsed" data-section="stok">', false)
            ->assertSee('<div class="nav-group collapsed" data-section="operasional">', false)
            ->assertSee('<div class="nav-group collapsed" data-section="laporan">', false);
    }

    public function test_status_bar_menampilkan_tanggal_bisnis_dan_peringatan_eod(): void
    {
        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Business Date')
            ->assertSee('data-timezone="Asia/Jakarta"', false)
            ->assertDontSee('belum diselesaikan');

        Sale::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'number' => 'S-0001',
            'client_uuid' => (string) Str::uuid(),
            'business_date' => '2026-09-15',
            'sold_at' => '2026-09-15 10:00:00',
            'cashier_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('EOD STR001 tanggal 15 September 2026 belum diselesaikan');
    }

    public function test_pencarian_log_aktivitas_tetap_menghormati_filter_aksi(): void
    {
        $log = fn (string $action, string $description) => ActivityLog::create([
            'company_id' => $this->company->id,
            'actor_id' => $this->owner->id,
            'actor_name' => 'Owner',
            'action' => $action,
            'description' => $description,
        ]);
        $updated = $log('update', 'Ubah produk Aqua');
        $deleted = $log('delete', 'Hapus produk Aqua');

        $this->actingAs($this->owner)
            ->get(route('admin.logs.index', ['q' => 'aqua', 'action' => 'update']))
            ->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->contains($updated) && ! $logs->contains($deleted));
    }
}
