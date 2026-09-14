<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
