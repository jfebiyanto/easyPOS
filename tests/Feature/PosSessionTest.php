<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CompanyDetail;
use App\Models\PaymentType;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosSessionTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

    private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';

    private CompanyDetail $company;

    private Store $store;

    private User $cashier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'ppn_enabled' => false, 'ppn_rate' => 0, 'allow_negative_stock' => true]);
        $this->store = Store::create(['company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);

        $this->cashier = User::create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Kasir', 'username' => 'kasir1',
            'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password',
        ]);
        $this->owner = User::create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Owner', 'username' => 'owner1',
            'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password',
        ]);
    }

    /**
     * Meniru satu browser/perangkat: cookie sesi, IP, dan User-Agent sendiri.
     */
    private function browser(string $cookie, string $ip, string $userAgent, ?User $user = null): static
    {
        return $this->actingAs($user ?? $this->cashier)
            ->withCredentials()
            ->withUnencryptedCookie(PosSession::DEVICE_COOKIE, str_pad($cookie, 40, 'x'))
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', $userAgent);
    }

    public function test_akses_pos_pertama_memulai_sesi_dan_mencatat_browser_dan_ip(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();

        $session = PosSession::firstOrFail();
        $this->assertSame($this->cashier->id, $session->user_id);
        $this->assertSame('10.0.0.5', $session->ip_address);
        $this->assertSame('Chrome 142 · Windows', $session->browser);
        $this->assertNull($session->ended_at);

        $log = ActivityLog::where('action', 'pos.session_started')->firstOrFail();
        $this->assertSame('Sesi POS Kasir dimulai dari Chrome 142 · Windows, IP 10.0.0.5', $log->description);
        $this->assertSame('10.0.0.5', $log->properties['ip_address']);
        $this->assertSame('Chrome 142 · Windows', $log->properties['browser']);
    }

    public function test_browser_yang_sama_memakai_satu_sesi_walau_diakses_berulang(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/products')->assertOk();
        $this->browser('a', '10.0.0.5', self::CHROME)->postJson('/pos/session/heartbeat')->assertOk();

        $this->assertSame(1, PosSession::count());
        $this->assertSame(1, ActivityLog::where('action', 'pos.session_started')->count());
    }

    public function test_perangkat_lain_ditolak_selama_sesi_pertama_aktif_dan_dicatat(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();

        $this->browser('b', '192.168.1.9', self::FIREFOX)->getJson('/pos/bootstrap')
            ->assertStatus(409)
            ->assertJsonPath('code', 'pos_session_active')
            ->assertJsonPath('active_session.browser', 'Chrome 142 · Windows')
            ->assertJsonPath('active_session.ip', '10.0.0.5');

        $this->assertSame(1, PosSession::count());
        $log = ActivityLog::where('action', 'pos.session_blocked')->firstOrFail();
        $this->assertStringContainsString('Firefox 130 · Linux, IP 192.168.1.9', $log->description);
        $this->assertStringContainsString('Chrome 142 · Windows, IP 10.0.0.5', $log->description);
    }

    public function test_perangkat_yang_ditolak_tidak_bisa_memakai_endpoint_pos_lain_termasuk_menjual(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();

        $this->browser('b', '192.168.1.9', self::FIREFOX)->getJson('/pos/products')->assertStatus(409);
        $this->browser('b', '192.168.1.9', self::FIREFOX)->postJson('/pos/sales', ['client_uuid' => (string) Str::uuid(), 'origin' => 'online'])->assertStatus(409);
        $this->browser('b', '192.168.1.9', self::FIREFOX)->postJson('/pos/session/heartbeat')->assertStatus(409);
    }

    public function test_ambil_alih_mengakhiri_sesi_lama_dan_mencatat_kedua_perangkat(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();

        $this->browser('b', '192.168.1.9', self::FIREFOX)->postJson('/pos/session/takeover')->assertOk()->assertJsonPath('data.taken_over', true);

        $old = PosSession::where('ip_address', '10.0.0.5')->firstOrFail();
        $this->assertSame(PosSession::ENDED_TAKEN_OVER, $old->ended_reason);
        $this->assertNotNull($old->ended_at);

        $log = ActivityLog::where('action', 'pos.session_taken_over')->firstOrFail();
        $this->assertSame('Sesi POS Kasir diambil alih oleh Firefox 130 · Linux, IP 192.168.1.9 dari Chrome 142 · Windows, IP 10.0.0.5', $log->description);
        $this->assertSame('10.0.0.5', $log->properties['replaced_session']['ip']);

        // Perangkat baru kini aktif, perangkat lama tergeser (409).
        $this->browser('b', '192.168.1.9', self::FIREFOX)->getJson('/pos/bootstrap')->assertOk();
        $this->browser('a', '10.0.0.5', self::CHROME)->postJson('/pos/session/heartbeat')->assertStatus(409)->assertJsonPath('active_session.ip', '192.168.1.9');
    }

    public function test_sesi_tanpa_detak_kedaluwarsa_dan_perangkat_lain_masuk_tanpa_ambil_alih(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();
        PosSession::query()->update(['last_seen_at' => now()->subSeconds(PosSession::STALE_AFTER_SECONDS + 5)]);

        $this->browser('b', '192.168.1.9', self::FIREFOX)->getJson('/pos/bootstrap')->assertOk();

        $this->assertSame(PosSession::ENDED_EXPIRED, PosSession::where('ip_address', '10.0.0.5')->firstOrFail()->ended_reason);
        $this->assertDatabaseHas('activity_logs', ['action' => 'pos.session_expired']);
        $this->assertSame(1, PosSession::whereNull('ended_at')->count());
    }

    public function test_perubahan_ip_di_tengah_sesi_dicatat(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();
        $this->browser('a', '10.0.0.77', self::CHROME)->getJson('/pos/products')->assertOk();

        $this->assertSame('10.0.0.77', PosSession::firstOrFail()->ip_address);
        $log = ActivityLog::where('action', 'pos.session_ip_changed')->firstOrFail();
        $this->assertSame('IP sesi POS Kasir berubah dari 10.0.0.5 menjadi 10.0.0.77', $log->description);
    }

    public function test_kasir_berbeda_punya_sesi_masing_masing(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();
        $this->browser('b', '10.0.0.6', self::FIREFOX, $this->owner)->getJson('/pos/bootstrap')->assertOk();

        $this->assertSame(2, PosSession::whereNull('ended_at')->count());
    }

    public function test_penjualan_offline_yang_disinkronkan_tetap_diterima_dari_perangkat_tergeser(): void
    {
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum', 'is_active' => true]);
        $product = Product::create(['company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id, 'sku' => 'SKU-1', 'name' => 'Aqua', 'base_price' => 4000, 'track_stock' => false, 'is_active' => true]);
        $cash = PaymentType::create(['company_id' => $this->company->id, 'code' => 'CASH', 'order_number' => 1, 'count_type' => 'summary', 'is_active' => true]);
        $cash->stores()->sync([$this->store->id]);

        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();

        $this->browser('b', '192.168.1.9', self::FIREFOX)->postJson('/pos/sales', [
            'client_uuid' => (string) Str::uuid(), 'origin' => 'offline', 'store_id' => $this->store->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['payment_type_id' => $cash->id, 'amount' => 4000, 'status' => 'confirmed']],
        ])->assertCreated();
    }

    public function test_keluar_mengakhiri_sesi_pos_browser_itu(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();

        $this->browser('a', '10.0.0.5', self::CHROME)->post('/logout')->assertRedirect();

        $session = PosSession::firstOrFail();
        $this->assertSame(PosSession::ENDED_LOGOUT, $session->ended_reason);
    }

    public function test_halaman_audit_sesi_kasir_untuk_owner(): void
    {
        $this->browser('a', '10.0.0.5', self::CHROME)->getJson('/pos/bootstrap')->assertOk();
        $this->browser('b', '192.168.1.9', self::FIREFOX)->postJson('/pos/session/takeover')->assertOk();

        $this->actingAs($this->owner)->get(route('admin.pos-sessions.index'))
            ->assertOk()
            ->assertSee('Cashier Sessions')
            ->assertSee('Chrome 142 · Windows')
            ->assertSee('Firefox 130 · Linux')
            ->assertSee('10.0.0.5')
            ->assertSee('192.168.1.9')
            ->assertSee('Taken over');

        $this->get(route('admin.pos-sessions.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee('192.168.1.9')
            ->assertDontSee('Ended ·');
    }

    public function test_halaman_audit_sesi_kasir_hanya_untuk_owner(): void
    {
        $this->actingAs($this->cashier)->get(route('admin.pos-sessions.index'))->assertForbidden();
    }
}
