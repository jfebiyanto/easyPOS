<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CompanyDetail;
use App\Models\PaymentType;
use App\Models\PosActiveCart;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\StartsShift;
use Tests\TestCase;

class PosCartTest extends TestCase
{
    use RefreshDatabase, StartsShift;

    private CompanyDetail $company;

    private Store $store;

    private User $cashier;

    private User $otherCashier;

    private Product $product;

    private PaymentType $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'ppn_enabled' => false, 'ppn_rate' => 0, 'allow_negative_stock' => true]);
        $this->store = Store::create(['company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);

        $make = fn (string $username) => User::create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => ucfirst($username), 'username' => $username,
            'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password',
        ]);
        $this->cashier = $make('kasir1');
        $this->otherCashier = $make('kasir2');

        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum', 'is_active' => true]);
        $this->product = Product::create([
            'company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id, 'sku' => 'SKU-1',
            'name' => 'Aqua', 'base_price' => 4000, 'track_stock' => false, 'is_active' => true,
        ]);
        $this->cash = PaymentType::create([
            'company_id' => $this->company->id, 'code' => 'CASH', 'order_number' => 1, 'count_type' => 'summary', 'is_active' => true,
        ]);
        $this->cash->stores()->sync([$this->store->id]);
        $this->startShiftFor($this->cashier);
    }

    /**
     * @return array{payload: array<string, mixed>}
     */
    private function cartPayload(int $qty = 3): array
    {
        return ['payload' => [
            'cart' => [['id' => $this->product->id, 'sku' => 'SKU-1', 'name' => 'Aqua', 'unit_price' => 4000, 'qty' => $qty]],
            'delivery' => ['enabled' => false],
            'customer' => null,
            'saved_at' => now()->toIso8601String(),
        ]];
    }

    public function test_keranjang_disimpan_otomatis_dan_dipulihkan_apa_adanya(): void
    {
        $this->actingAs($this->cashier)->putJson('/pos/cart', $this->cartPayload(3))->assertOk();

        $this->assertDatabaseHas('pos_active_carts', ['cashier_id' => $this->cashier->id, 'item_count' => 1, 'gross_total' => 12000]);

        $this->postJson('/pos/cart/restore')->assertOk()
            ->assertJsonPath('data.cart.0.qty', 3)
            ->assertJsonPath('data.cart.0.sku', 'SKU-1')
            ->assertJsonPath('data.delivery.enabled', false);
    }

    public function test_menyimpan_ulang_memperbarui_satu_baris_per_kasir(): void
    {
        $this->actingAs($this->cashier)->putJson('/pos/cart', $this->cartPayload(1))->assertOk();
        $this->putJson('/pos/cart', $this->cartPayload(5))->assertOk();

        $this->assertSame(1, PosActiveCart::count());
        $this->assertEquals(20000, PosActiveCart::firstOrFail()->gross_total);
    }

    public function test_keranjang_kosong_menghapus_simpanan(): void
    {
        $this->actingAs($this->cashier)->putJson('/pos/cart', $this->cartPayload())->assertOk();
        $this->putJson('/pos/cart', ['payload' => ['cart' => []]])->assertOk();

        $this->assertSame(0, PosActiveCart::count());
        $this->postJson('/pos/cart/restore')->assertOk()->assertJsonPath('data', null);
    }

    public function test_pemulihan_dicatat_di_log_aktivitas(): void
    {
        $this->actingAs($this->cashier)->putJson('/pos/cart', $this->cartPayload(3))->assertOk();
        $this->postJson('/pos/cart/restore')->assertOk();

        $log = ActivityLog::where('action', 'pos.cart_restored')->firstOrFail();
        $this->assertSame($this->cashier->id, $log->actor_id);
        $this->assertStringContainsString('1 barang', $log->description);
        $this->assertStringContainsString('Rp 12.000', $log->description);
    }

    public function test_keranjang_kedaluwarsa_dibuang_dan_dicatat(): void
    {
        $this->actingAs($this->cashier)->putJson('/pos/cart', $this->cartPayload())->assertOk();
        PosActiveCart::query()->update(['updated_at' => now()->subHours(PosActiveCart::EXPIRES_AFTER_HOURS + 1)]);

        $this->postJson('/pos/cart/restore')->assertOk()->assertJsonPath('data', null);

        $this->assertSame(0, PosActiveCart::count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'pos.cart_expired']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'pos.cart_restored']);
    }

    public function test_keranjang_kasir_lain_tidak_ikut_dipulihkan(): void
    {
        $this->actingAs($this->cashier)->putJson('/pos/cart', $this->cartPayload())->assertOk();

        $this->actingAs($this->otherCashier)->postJson('/pos/cart/restore')->assertOk()->assertJsonPath('data', null);
    }

    public function test_penjualan_online_selesai_menghapus_keranjang_tersimpan_tetapi_sinkron_offline_tidak(): void
    {
        $this->actingAs($this->cashier)->putJson('/pos/cart', $this->cartPayload())->assertOk();
        $sale = fn (string $origin) => [
            'client_uuid' => (string) Str::uuid(), 'origin' => $origin,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 4000, 'status' => 'confirmed']],
        ];

        $this->postJson('/pos/sales', $sale('offline'))->assertCreated();
        $this->assertSame(1, PosActiveCart::count(), 'Antrean offline bukan transaksi yang sedang berjalan.');

        $this->postJson('/pos/sales', $sale('online'))->assertCreated();
        $this->assertSame(0, PosActiveCart::count());
    }

    public function test_batal_transaksi_dicatat_dan_menghapus_keranjang_tersimpan(): void
    {
        $this->actingAs($this->cashier)->putJson('/pos/cart', $this->cartPayload())->assertOk();

        $this->postJson('/pos/cart/events', ['event' => 'cancelled', 'item_count' => 2, 'total' => 20000])->assertCreated();

        $this->assertSame(0, PosActiveCart::count());
        $log = ActivityLog::where('action', 'pos.cart_cancelled')->firstOrFail();
        $this->assertSame('Batal transaksi di STR001: 2 barang, total Rp 20.000', $log->description);
        $this->assertSame($this->cashier->id, $log->actor_id);
    }

    public function test_hapus_item_dan_pengurangan_qty_dicatat(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/pos/cart/events', ['event' => 'item_removed', 'sku' => 'SKU-1', 'name' => 'Aqua', 'qty' => 5, 'amount' => 20000])
            ->assertCreated();
        $this->postJson('/pos/cart/events', ['event' => 'qty_reduced', 'sku' => 'SKU-1', 'name' => 'Aqua', 'from_qty' => 5, 'to_qty' => 1])
            ->assertCreated();

        $this->assertSame('Hapus item di STR001: SKU-1 Aqua × 5 (Rp 20.000)', ActivityLog::where('action', 'pos.cart_item_removed')->firstOrFail()->description);
        $this->assertSame('Qty dikurangi di STR001: SKU-1 Aqua dari 5 menjadi 1', ActivityLog::where('action', 'pos.cart_qty_reduced')->firstOrFail()->description);
    }

    public function test_halaman_ditinggalkan_saat_transaksi_berjalan_dicatat_tanpa_menghapus_keranjang(): void
    {
        $this->actingAs($this->cashier)->putJson('/pos/cart', $this->cartPayload(3))->assertOk();

        $this->postJson('/pos/cart/events', ['event' => 'left_open', 'item_count' => 1, 'total' => 12000])->assertCreated();

        $this->assertSame('Halaman POS ditutup atau dimuat ulang saat transaksi berjalan di STR001: 1 barang, nilai Rp 12.000', ActivityLog::where('action', 'pos.cart_left_open')->firstOrFail()->description);
        $this->assertSame(1, PosActiveCart::count(), 'Keranjang tetap tersimpan agar bisa dipulihkan.');
    }

    public function test_tab_pos_kedua_yang_diblokir_dicatat(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/cart/events', ['event' => 'duplicate_tab'])->assertCreated();

        $log = ActivityLog::where('action', 'pos.duplicate_tab_blocked')->firstOrFail();
        $this->assertSame('Tab POS kedua diblokir di STR001: POS sudah terbuka di tab lain pada browser yang sama', $log->description);
        $this->assertSame($this->cashier->id, $log->actor_id);
    }

    public function test_peristiwa_keranjang_dan_penyimpanan_divalidasi(): void
    {
        $this->actingAs($this->cashier);

        $this->postJson('/pos/cart/events', ['event' => 'hapus-semua'])->assertUnprocessable();
        $this->putJson('/pos/cart', ['payload' => ['cart' => [['id' => $this->product->id, 'qty' => -1, 'unit_price' => 1]]]])->assertUnprocessable();
        $this->putJson('/pos/cart', ['payload' => ['cart' => array_fill(0, 301, ['id' => 'x', 'qty' => 1, 'unit_price' => 1])]])->assertUnprocessable();
    }
}
