<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Store;
use App\Models\User;
use App\Support\BackofficeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackofficeAccessTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'ppn_enabled' => false, 'ppn_rate' => 0]);
        $this->store = Store::create(['company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);

        $this->owner = $this->makeUser('owner1', User::ROLE_OWNER, $this->store);
        $this->manager = $this->makeUser('manager1', User::ROLE_STORE_MANAGER, $this->store);
        $this->cashier = $this->makeUser('kasir1', User::ROLE_CASHIER, $this->store);
    }

    private function makeUser(string $username, string $role, ?Store $store): User
    {
        return User::create([
            'company_id' => $this->company->id, 'store_id' => $store?->id, 'name' => ucfirst($username), 'username' => $username,
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    public function test_owner_diizinkan_untuk_semua_rute(): void
    {
        foreach (['admin.company.edit', 'admin.products.store', 'admin.users.update', 'admin.numberings.update'] as $name) {
            $this->assertTrue(BackofficeAccess::allows($this->owner, $name, 'POST'), $name);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function closedRoutes(): array
    {
        return [
            'identitas perusahaan' => ['admin.company.edit'],
            'simpan SAP' => ['admin.company.update-sap'],
            'penomoran' => ['admin.numberings.index'],
            'impor SAP' => ['admin.sap-import.index'],
            'integration monitor' => ['admin.sap-postings.index'],
        ];
    }

    #[DataProvider('closedRoutes')]
    public function test_store_manager_ditutup_dari_empat_kelompok(string $name): void
    {
        $this->assertFalse(BackofficeAccess::allows($this->manager, $name, 'GET'));
        $this->assertFalse(BackofficeAccess::allows($this->manager, $name, 'POST'));
    }

    public function test_store_manager_ditolak_403_di_halaman_tertutup(): void
    {
        $this->actingAs($this->manager)->get(route('admin.numberings.index'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('admin.company.edit'))->assertForbidden();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writableRoutes(): array
    {
        return [
            'simpan EOD' => ['admin.eod.store', 'POST'],
            'buka ulang EOD' => ['admin.eod.reopen', 'POST'],
            'selesaikan review stok' => ['admin.stock-review.resolve', 'POST'],
            'simpan penyesuaian' => ['admin.stock-adjustments.store', 'POST'],
            'formulir penyesuaian' => ['admin.stock-adjustments.create', 'GET'],
            'simpan shipment' => ['admin.stock-transfer-shipments.store', 'POST'],
            'ubah pengiriman' => ['admin.deliveries.update', 'PUT'],
        ];
    }

    #[DataProvider('writableRoutes')]
    public function test_store_manager_boleh_menulis_di_lima_area(string $name, string $method): void
    {
        $this->assertTrue(BackofficeAccess::allows($this->manager, $name, $method));
        $this->assertFalse(BackofficeAccess::isReadOnly($this->manager, $name));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function masterRoutes(): array
    {
        return [
            'baca produk' => ['admin.products.index', 'GET', true],
            'baca hierarki promosi' => ['admin.promotion-hierarchy.edit', 'GET', true],
            'simpan produk' => ['admin.products.store', 'POST', false],
            'ubah user' => ['admin.users.update', 'PUT', false],
            'hapus pelanggan' => ['admin.customers.destroy', 'DELETE', false],
            'formulir tambah produk' => ['admin.products.create', 'GET', false],
            'formulir ubah user' => ['admin.users.edit', 'GET', false],
            'ganti gerai aktif' => ['admin.stores.switch', 'POST', false],
            'simpan hierarki promosi' => ['admin.promotion-hierarchy.update', 'PUT', false],
        ];
    }

    #[DataProvider('masterRoutes')]
    public function test_store_manager_hanya_membaca_master(string $name, string $method, bool $expected): void
    {
        $this->assertSame($expected, BackofficeAccess::allows($this->manager, $name, $method));
    }

    public function test_sapu_semua_rute_admin_non_get_untuk_store_manager(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'admin.')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
                $checked++;
                $writable = collect(BackofficeAccess::WRITABLE)->contains(fn (string $prefix) => str_starts_with($name, $prefix));

                $this->assertSame($writable, BackofficeAccess::allows($this->manager, $name, $method), "{$method} {$name}");
            }
        }

        $this->assertGreaterThan(50, $checked);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readablePages(): array
    {
        $names = [
            'admin.stores.index', 'admin.warehouses.index', 'admin.tax-groups.index', 'admin.users.index',
            'admin.member-levels.index', 'admin.currencies.index', 'admin.payment-types.index',
            'admin.customer-groups.index', 'admin.promotion-hierarchy.edit', 'admin.products.index',
            'admin.customers.index', 'admin.price-lists.index', 'admin.promotions.index', 'admin.groups.index',
            'admin.units.index', 'admin.brands.index',
        ];

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    #[DataProvider('readablePages')]
    public function test_store_manager_dapat_membuka_halaman_baca(string $name): void
    {
        $this->actingAs($this->manager)->get(route($name))->assertOk();
    }

    public function test_store_manager_tidak_dapat_menyimpan_master(): void
    {
        $this->actingAs($this->manager)->put(route('admin.promotion-hierarchy.update'), ['order' => ['buy_x_get_y']])->assertForbidden();
        $this->actingAs($this->manager)->post(route('admin.users.reset-password', $this->cashier))->assertForbidden();
        $this->actingAs($this->manager)->post(route('admin.member-levels.store'), ['name' => 'Gold'])->assertForbidden();
    }

    public function test_menu_store_manager_menyembunyikan_halaman_tertutup_dan_menampilkan_sisanya(): void
    {
        $response = $this->actingAs($this->manager)->get(route('admin.dashboard'))->assertOk();

        foreach (['admin.company.edit', 'admin.numberings.index'] as $name) {
            $response->assertDontSee('href="'.route($name).'"', false);
        }
        foreach (['admin.users.index', 'admin.eod.index', 'admin.logs.index', 'admin.pos-sessions.index', 'admin.stores.index'] as $name) {
            $response->assertSee('href="'.route($name).'"', false);
        }
    }

    public function test_caption_menu_memakai_bahasa_inggris(): void
    {
        $response = $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();

        foreach (['Settings', 'Products', 'Inventory', 'Operations', 'Reports'] as $group) {
            $response->assertSee('<span class="nav-section-label">'.$group.'</span>', false);
        }
        foreach (['Company Profile', 'Stores', 'Users', 'Payment Methods', 'Product Catalog', 'Price Lists', 'Stock Balance', 'Stock Counting', 'Stock Transfer Shipment', 'Stock Transfer Receipt', 'Deliveries', 'Stock Review', 'End of Day', 'Sales Reports', 'Voids &amp; Cancellations', 'Activity Log', 'Cashier Sessions'] as $caption) {
            $response->assertSee('<span>'.$caption.'</span>', false);
        }
        foreach (['Saldo Stok', 'Daftar Produk', 'Tutup Toko', 'Penyesuaian Stok'] as $old) {
            $response->assertDontSee('<span>'.$old.'</span>', false);
        }
        $response->assertDontSee('<span class="nav-section-label">Konfigurasi</span>', false);
    }

    public function test_menu_owner_tetap_lengkap(): void
    {
        $response = $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();

        foreach (['admin.company.edit', 'admin.numberings.index', 'admin.users.index'] as $name) {
            $response->assertSee('href="'.route($name).'"', false);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function masterListPages(): array
    {
        $names = [
            'admin.products', 'admin.customers', 'admin.price-lists', 'admin.promotions', 'admin.groups', 'admin.units',
            'admin.brands', 'admin.stores', 'admin.warehouses', 'admin.tax-groups', 'admin.users', 'admin.currencies',
            'admin.payment-types', 'admin.customer-groups', 'admin.member-levels',
        ];

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    #[DataProvider('masterListPages')]
    public function test_halaman_baca_saja_tidak_menampilkan_tombol_tulis_untuk_store_manager(string $prefix): void
    {
        $this->actingAs($this->manager)->get(route($prefix.'.index'))
            ->assertOk()
            ->assertDontSee(route($prefix.'.create'), false)
            ->assertDontSee('name="_method" value="DELETE"', false);
    }

    #[DataProvider('masterListPages')]
    public function test_owner_tetap_melihat_tombol_tambah(string $prefix): void
    {
        $this->actingAs($this->owner)->get(route($prefix.'.index'))->assertOk()->assertSee(route($prefix.'.create'), false);
    }

    public function test_area_tulis_menampilkan_tombol_untuk_store_manager(): void
    {
        $this->actingAs($this->manager)->get(route('admin.stock-adjustments.index'))
            ->assertOk()
            ->assertSee(route('admin.stock-adjustments.create'), false);
    }

    public function test_store_manager_tanpa_gerai_ditolak_403(): void
    {
        $stateless = $this->makeUser('manager2', User::ROLE_STORE_MANAGER, null);

        $this->actingAs($stateless)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_penolakan_permintaan_json_mengembalikan_403_json(): void
    {
        $this->actingAs($this->manager)->getJson(route('admin.numberings.index'))
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_kasir_tetap_ditolak_dari_backoffice(): void
    {
        $this->actingAs($this->cashier)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_owner_dan_store_manager_membuka_dashboard(): void
    {
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($this->manager)->get(route('admin.dashboard'))->assertOk();
    }
}
