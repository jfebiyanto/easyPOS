<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 WIB, 28 Oktober 2026.
        $this->travelTo('2026-10-28 03:00:00');

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'timezone' => 'Asia/Jakarta']);
        $this->store = Store::create(['company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $this->owner = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Owner', 'username' => 'owner1', 'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password']);
    }

    private function cashier(string $name): User
    {
        return User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => $name, 'username' => Str::slug($name), 'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password']);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: float, 3: float}>  $lines  [sku, nama, qty, line_total]
     */
    private function sale(User $cashier, string $soldAtUtc, string $businessDate, array $lines, float $discount = 0, string $status = Sale::STATUS_COMPLETED, ?Store $store = null): Sale
    {
        $sale = Sale::create([
            'company_id' => $this->company->id,
            'store_id' => ($store ?? $this->store)->id,
            'client_uuid' => (string) Str::uuid(),
            'business_date' => $businessDate,
            'cashier_id' => $cashier->id,
            'status' => $status,
            'discount_total' => $discount,
            'total' => array_sum(array_column($lines, 3)),
            'sold_at' => $soldAtUtc,
        ]);

        foreach ($lines as [$sku, $name, $qty, $total]) {
            $sale->lines()->create(['sku_snapshot' => $sku, 'name_snapshot' => $name, 'qty' => $qty, 'unit_price' => $total / $qty, 'line_total' => $total]);
        }

        return $sale;
    }

    public function test_data_penjualan_hari_ini_dihitung_per_jam_kasir_dan_produk(): void
    {
        $sari = $this->cashier('Sari');
        $budi = $this->cashier('Budi');

        // Hari ini (WIB): 08:15 dan 09:30.
        $this->sale($sari, '2026-10-28 01:15:00', '2026-10-28', [['SKU-0001', 'Aqua', 3, 15000], ['SKU-0002', 'Teh', 1, 5000]], discount: 2000);
        $this->sale($budi, '2026-10-28 02:30:00', '2026-10-28', [['SKU-0001', 'Aqua', 2, 10000]]);
        $this->sale($budi, '2026-10-28 02:45:00', '2026-10-28', [['SKU-0002', 'Teh', 9, 45000]], status: Sale::STATUS_VOID);
        // Kemarin 08:00 WIB.
        $this->sale($sari, '2026-10-27 01:00:00', '2026-10-27', [['SKU-0001', 'Aqua', 4, 20000]]);
        SalesReturn::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'total' => 5000, 'created_by' => $sari->id]);

        $res = $this->actingAs($this->owner)->getJson(route('admin.dashboard.today'))->assertOk();

        $res->assertJsonPath('data.date', '2026-10-28')
            ->assertJsonPath('data.sales.total', 30000)
            ->assertJsonPath('data.sales.transactions', 2)
            ->assertJsonPath('data.sales.delta_pct', 50)
            ->assertJsonPath('data.sales.hourly.8', 20000)
            ->assertJsonPath('data.sales.hourly.9', 10000)
            ->assertJsonPath('data.sales.top_cashiers.0.name', 'Sari')
            ->assertJsonPath('data.sales.top_cashiers.1.name', 'Budi')
            ->assertJsonPath('data.products.top.name', 'Aqua')
            ->assertJsonPath('data.products.top.qty', 5)
            ->assertJsonPath('data.products.top.delta_pct', 25)
            ->assertJsonPath('data.products.list.1.name', 'Teh')
            ->assertJsonPath('data.performance.net_sales', 25000)
            ->assertJsonPath('data.performance.deductions', 7000)
            ->assertJsonPath('data.performance.expense_hourly.10', 5000)
            ->assertJsonPath('data.performance.target_hourly.8', round(20000 / 7, 2));
    }

    public function test_tanpa_transaksi_mengembalikan_data_kosong(): void
    {
        $this->actingAs($this->owner)->getJson(route('admin.dashboard.today'))
            ->assertOk()
            ->assertJsonPath('data.sales.total', 0)
            ->assertJsonPath('data.sales.delta_pct', null)
            ->assertJsonPath('data.sales.top_cashiers', [])
            ->assertJsonPath('data.products.top', null)
            ->assertJsonPath('data.performance.net_target_pct', null)
            ->assertJsonCount(24, 'data.sales.hourly');
    }

    public function test_kasir_tidak_dapat_mengakses_data_dashboard(): void
    {
        $this->actingAs($this->cashier('Sari'))->getJson(route('admin.dashboard.today'))->assertForbidden();
    }

    public function test_dashboard_menampilkan_section_penjualan_hari_ini(): void
    {
        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee("Today's Sales · STR001", false)
            ->assertSee(route('admin.dashboard.today'), false);
    }

    public function test_widget_dashboard_hanya_menampilkan_grafik_tanpa_daftar_dan_grafik_target(): void
    {
        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-ts-chart="sales"', false)
            ->assertSee('data-ts-chart="products"', false)
            ->assertSee('data-ts-ring="net"', false)
            ->assertDontSee('data-ts-chart="target"', false)
            ->assertDontSee('data-ts-mode', false)
            ->assertDontSee('Top Sales')
            ->assertDontSee('Top Performing')
            ->assertDontSee('Target Penjualan')
            ->assertDontSee('data-ts-list', false)
            ->assertDontSee('data-ts-target-note', false);
    }

    private function secondStore(): Store
    {
        return Store::create(['company_id' => $this->company->id, 'code' => 'STR002', 'name' => 'Gerai B', 'pic_name' => 'PIC', 'currency' => 'IDR']);
    }

    private function manager(Store $store): User
    {
        return User::create(['company_id' => $this->company->id, 'store_id' => $store->id, 'name' => 'Manager', 'username' => 'manager'.$store->code, 'role' => User::ROLE_STORE_MANAGER, 'is_active' => true, 'password' => 'password']);
    }

    /**
     * Dua gerai: A (2 penjualan selesai 30.000, 1 void, 1 retur hari ini, 1 retur kemarin), B (1 penjualan 7.000, 1 retur hari ini).
     */
    private function seedTwoStores(): Store
    {
        $storeB = $this->secondStore();
        $sari = $this->cashier('Sari');

        $this->sale($sari, '2026-10-28 01:15:00', '2026-10-28', [['SKU-0001', 'Aqua', 4, 20000]]);
        $this->sale($sari, '2026-10-28 02:30:00', '2026-10-28', [['SKU-0001', 'Aqua', 2, 10000]]);
        $this->sale($sari, '2026-10-28 02:45:00', '2026-10-28', [['SKU-0002', 'Teh', 9, 45000]], status: Sale::STATUS_VOID);
        $this->sale($sari, '2026-10-28 03:00:00', '2026-10-28', [['SKU-0002', 'Teh', 1, 7000]], store: $storeB);

        SalesReturn::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'total' => 5000, 'created_by' => $sari->id]);
        SalesReturn::create(['company_id' => $this->company->id, 'store_id' => $storeB->id, 'total' => 2000, 'created_by' => $sari->id]);
        $yesterdayReturn = SalesReturn::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'total' => 9000, 'created_by' => $sari->id]);
        SalesReturn::whereKey($yesterdayReturn->id)->update(['created_at' => '2026-10-27 03:00:00']);

        return $storeB;
    }

    public function test_ringkasan_semua_gerai_menghitung_transaksi_penjualan_retur_dan_perbandingan_antar_gerai(): void
    {
        $this->seedTwoStores();

        $res = $this->actingAs($this->owner)->getJson(route('admin.dashboard.overview'))->assertOk();

        $res->assertJsonPath('data.date', '2026-10-28')
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonPath('data.sales_transactions', 3)
            ->assertJsonPath('data.returns_transactions', 2)
            ->assertJsonCount(2, 'data.stores')
            ->assertJsonPath('data.stores.0.code', 'STR001')
            ->assertJsonPath('data.stores.0.total', 30000)
            ->assertJsonPath('data.stores.0.transactions', 2)
            ->assertJsonPath('data.stores.1.code', 'STR002')
            ->assertJsonPath('data.stores.1.total', 7000)
            ->assertJsonPath('data.stores.1.transactions', 1);
    }

    public function test_ringkasan_menampilkan_gerai_tanpa_penjualan_dengan_nol(): void
    {
        $this->secondStore();

        $res = $this->actingAs($this->owner)->getJson(route('admin.dashboard.overview'))->assertOk();

        $res->assertJsonPath('data.sales_transactions', 0)
            ->assertJsonPath('data.returns_transactions', 0)
            ->assertJsonCount(2, 'data.stores')
            ->assertJsonPath('data.stores.1.total', 0);
    }

    public function test_ringkasan_store_manager_hanya_gerainya(): void
    {
        $storeB = $this->seedTwoStores();

        $res = $this->actingAs($this->manager($storeB))->getJson(route('admin.dashboard.overview'))->assertOk();

        $res->assertJsonPath('data.scope', 'store')
            ->assertJsonPath('data.sales_transactions', 1)
            ->assertJsonPath('data.returns_transactions', 1)
            ->assertJsonCount(1, 'data.stores')
            ->assertJsonPath('data.stores.0.code', 'STR002');
    }

    public function test_kasir_tidak_dapat_mengakses_ringkasan(): void
    {
        $this->actingAs($this->cashier('Sari'))->getJson(route('admin.dashboard.overview'))->assertForbidden();
    }

    public function test_dashboard_menampilkan_widget_hitungan_dan_grafik_perbandingan_untuk_owner(): void
    {
        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-ov-url="'.route('admin.dashboard.overview').'"', false)
            ->assertSee("Today's Sales Transactions", false)
            ->assertSee("Today's Return Transactions", false)
            ->assertSee('Sales Comparison Across Stores')
            ->assertSee('data-ov-chart="stores"', false);
    }

    public function test_dashboard_store_manager_tanpa_grafik_perbandingan(): void
    {
        $this->actingAs($this->manager($this->store))->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee("Today's Sales Transactions", false)
            ->assertSee("Today's Return Transactions", false)
            ->assertDontSee('Sales Comparison Across Stores')
            ->assertDontSee('data-ov-chart="stores"', false);
    }
}
