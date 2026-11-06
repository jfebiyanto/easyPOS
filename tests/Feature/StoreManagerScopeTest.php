<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CompanyDetail;
use App\Models\Delivery;
use App\Models\PosSession;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreManagerScopeTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $storeA;

    private Store $storeB;

    private User $owner;

    private User $managerA;

    private User $cashierB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'ppn_enabled' => false, 'ppn_rate' => 0]);
        $this->storeA = $this->makeStore('STR001', 'Gerai A');
        $this->storeB = $this->makeStore('STR002', 'Gerai B');

        $this->owner = $this->makeUser('owner1', User::ROLE_OWNER, $this->storeA);
        $this->managerA = $this->makeUser('manager1', User::ROLE_STORE_MANAGER, $this->storeA);
        $this->cashierB = $this->makeUser('kasirb', User::ROLE_CASHIER, $this->storeB);
    }

    private function makeStore(string $code, string $name): Store
    {
        return Store::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'pic_name' => 'PIC', 'currency' => 'IDR']);
    }

    private function makeUser(string $username, string $role, Store $store): User
    {
        return User::create([
            'company_id' => $this->company->id, 'store_id' => $store->id, 'name' => ucfirst($username), 'username' => $username,
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function makeDelivery(Store $store): Delivery
    {
        $sale = Sale::create([
            'company_id' => $this->company->id, 'store_id' => $store->id, 'business_date' => company_today(), 'type' => 'sale',
            'status' => 'completed', 'total' => 20000, 'currency_code' => 'IDR', 'sold_at' => now(),
        ]);

        return Delivery::create(['sale_id' => $sale->id, 'recipient_name' => 'Budi', 'phone' => '0812', 'address' => 'Jl. Merdeka 1', 'shipping_cost' => 5000, 'status' => 'pending']);
    }

    private function makeSession(User $user): PosSession
    {
        return PosSession::create([
            'company_id' => $this->company->id, 'user_id' => $user->id, 'store_id' => $user->store_id, 'session_hash' => str_repeat('a', 63).$user->username[0],
            'ip_address' => '10.0.0.9', 'user_agent' => 'UA', 'browser' => 'Chrome 142 · Windows', 'started_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_activity_logger_mengisi_gerai_user_yang_beraksi(): void
    {
        $this->actingAs($this->managerA);

        ActivityLogger::log('test.action', 'Aksi uji');

        $this->assertSame($this->storeA->id, ActivityLog::where('action', 'test.action')->value('store_id'));
    }

    public function test_log_aktivitas_store_manager_hanya_gerainya_sedangkan_owner_melihat_semua(): void
    {
        ActivityLog::create(['company_id' => $this->company->id, 'actor_name' => 'Manager1', 'action' => 'a.x', 'description' => 'Log gerai A tampil', 'store_id' => $this->storeA->id]);
        ActivityLog::create(['company_id' => $this->company->id, 'actor_name' => 'Kasirb', 'action' => 'b.x', 'description' => 'Log gerai B tersembunyi', 'store_id' => $this->storeB->id]);
        ActivityLog::create(['company_id' => $this->company->id, 'actor_name' => 'system', 'action' => 'n.x', 'description' => 'Log tanpa gerai', 'store_id' => null]);

        $this->actingAs($this->managerA)->get(route('admin.logs.index'))
            ->assertOk()
            ->assertSee('Log gerai A tampil')
            ->assertDontSee('Log gerai B tersembunyi')
            ->assertDontSee('Log tanpa gerai');

        $this->actingAs($this->owner)->get(route('admin.logs.index'))
            ->assertOk()
            ->assertSee('Log gerai A tampil')
            ->assertSee('Log gerai B tersembunyi')
            ->assertSee('Log tanpa gerai');
    }

    public function test_sesi_kasir_store_manager_hanya_gerainya(): void
    {
        $this->makeSession($this->managerA);
        $this->makeSession($this->cashierB);

        $sessions = $this->actingAs($this->managerA)->get(route('admin.pos-sessions.index'))->assertOk()->viewData('sessions');

        $this->assertSame([$this->storeA->id], collect($sessions->items())->pluck('store_id')->unique()->values()->all());

        $all = $this->actingAs($this->owner)->get(route('admin.pos-sessions.index'))->assertOk()->viewData('sessions');
        $this->assertCount(2, $all->items());
    }

    public function test_parameter_store_diabaikan_store_manager_di_saldo_stok_dan_laporan(): void
    {
        $stock = $this->actingAs($this->managerA)->get(route('admin.stock.index', ['store' => $this->storeB->id]))->assertOk();
        $this->assertSame($this->storeA->id, $stock->viewData('storeId'));

        $rows = $this->actingAs($this->managerA)
            ->get(route('admin.reports.show', ['report' => 'sales_by_store', 'store' => $this->storeB->id]))
            ->assertOk()
            ->viewData('data')['rows'];

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('STR001', $rows[0][0]);
    }

    public function test_pengiriman_store_manager_hanya_gerainya(): void
    {
        $own = $this->makeDelivery($this->storeA);
        $foreign = $this->makeDelivery($this->storeB);

        $this->actingAs($this->managerA)->put(route('admin.deliveries.update', $own), ['status' => 'shipped'])->assertRedirect();
        $this->assertSame('shipped', $own->fresh()->status);

        $this->actingAs($this->managerA)->put(route('admin.deliveries.update', $foreign), ['status' => 'shipped'])->assertForbidden();
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_halaman_data_per_gerai_tampil_kosong_tanpa_error_untuk_gerai_tanpa_data(): void
    {
        foreach (['admin.dashboard', 'admin.reports.index', 'admin.stock.index', 'admin.logs.index', 'admin.pos-sessions.index', 'admin.cancellations.index', 'admin.deliveries.index', 'admin.stock-transfers.index'] as $name) {
            $this->actingAs($this->managerA)->get(route($name))->assertOk();
        }
    }
}
