<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\PaymentType;
use App\Models\PosCancellation;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CancellationReportTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

    private CompanyDetail $company;

    private Store $storeA;

    private Store $storeB;

    private User $owner;

    private User $managerA;

    private User $cashierA;

    private User $cashierB;

    private Customer $customer;

    private Product $product;

    private PaymentType $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'timezone' => 'Asia/Jakarta', 'ppn_enabled' => false, 'ppn_rate' => 0, 'allow_negative_stock' => true]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'code' => 'CR00001', 'name' => 'PELANGGAN UMUM', 'is_active' => true]);
        $store = fn (string $code, string $name) => Store::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'pic_name' => 'PIC', 'currency' => 'IDR', 'default_customer_id' => $this->customer->id]);
        $this->storeA = $store('STR001', 'Gerai A');
        $this->storeB = $store('STR002', 'Gerai B');

        $user = fn (Store $store, string $username, string $role) => User::create([
            'company_id' => $this->company->id, 'store_id' => $store->id, 'name' => ucfirst($username), 'username' => $username,
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
        $this->owner = $user($this->storeA, 'owner1', User::ROLE_OWNER);
        $this->managerA = $user($this->storeA, 'manager1', User::ROLE_STORE_MANAGER);
        $this->cashierA = $user($this->storeA, 'kasira', User::ROLE_CASHIER);
        $this->cashierB = $user($this->storeB, 'kasirb', User::ROLE_CASHIER);

        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum', 'is_active' => true]);
        $this->product = Product::create([
            'company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id, 'sku' => 'SKU-1',
            'name' => 'Aqua', 'base_price' => 4000, 'track_stock' => false, 'is_active' => true,
        ]);
        $this->cash = PaymentType::create(['company_id' => $this->company->id, 'code' => 'CASH', 'order_number' => 1, 'count_type' => 'summary', 'is_active' => true]);
        $this->cash->stores()->sync([$this->storeA->id, $this->storeB->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function cancelPayload(array $extra = []): array
    {
        return array_merge([
            'event' => 'cancelled',
            'item_count' => 2,
            'total' => 12000,
            'customer_id' => $this->customer->id,
            'items' => [
                ['sku' => 'SKU-1', 'name' => 'Aqua', 'qty' => 2, 'unit_price' => 4000, 'discount' => 0, 'line_total' => 8000],
                ['sku' => 'SKU-2', 'name' => 'Teh Botol', 'qty' => 1, 'unit_price' => 4000, 'discount' => 0, 'line_total' => 4000],
            ],
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function seedCancellation(Store $store, User $cashier, array $override = []): PosCancellation
    {
        return PosCancellation::create(array_merge([
            'company_id' => $this->company->id, 'type' => PosCancellation::TYPE_CART, 'store_id' => $store->id, 'store_label' => $store->code.' · '.$store->name,
            'cashier_id' => $cashier->id, 'cashier_name' => $cashier->name, 'customer_label' => 'CR00001 · PELANGGAN UMUM',
            'item_count' => 1, 'total' => 5000, 'items' => [['sku' => 'X', 'name' => 'Barang X', 'qty' => 1, 'unit_price' => 5000, 'discount' => 0, 'line_total' => 5000]],
            'occurred_at' => now(), 'ip_address' => '10.0.0.9', 'browser' => 'Chrome 142 · Windows',
        ], $override));
    }

    public function test_batal_transaksi_dicatat_lengkap_dengan_gerai_kasir_pelanggan_barang_ip_dan_perangkat(): void
    {
        $this->actingAs($this->cashierA)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeader('User-Agent', self::CHROME)
            ->postJson('/pos/cart/events', $this->cancelPayload(['reason' => 'Pelanggan batal']))
            ->assertCreated();

        $c = PosCancellation::firstOrFail();
        $this->assertSame(PosCancellation::TYPE_CART, $c->type);
        $this->assertSame($this->storeA->id, $c->store_id);
        $this->assertSame('STR001 · Gerai A', $c->store_label);
        $this->assertSame($this->cashierA->id, $c->cashier_id);
        $this->assertSame('Kasira', $c->cashier_name);
        $this->assertSame('CR00001 · PELANGGAN UMUM', $c->customer_label);
        $this->assertSame(2, $c->item_count);
        $this->assertEquals(12000, $c->total);
        $this->assertSame('Pelanggan batal', $c->reason);
        $this->assertSame(['SKU-1', 'SKU-2'], array_column($c->items, 'sku'));
        $this->assertSame('10.0.0.5', $c->ip_address);
        $this->assertSame('Chrome 142 · Windows', $c->browser);
        $this->assertSame(self::CHROME, $c->user_agent);
        $this->assertFalse($c->isLate());
    }

    public function test_pelanggan_kosong_memakai_pelanggan_default_gerai(): void
    {
        $this->actingAs($this->cashierA)->postJson('/pos/cart/events', $this->cancelPayload(['customer_id' => null]))->assertCreated();

        $this->assertSame('CR00001 · PELANGGAN UMUM', PosCancellation::firstOrFail()->customer_label);
    }

    public function test_pembatalan_saat_offline_memakai_waktu_kejadian_dan_ditandai_terlambat(): void
    {
        $happened = now()->subMinutes(30);

        $this->actingAs($this->cashierA)->postJson('/pos/cart/events', $this->cancelPayload(['occurred_at' => $happened->toIso8601String()]))->assertCreated();

        $c = PosCancellation::firstOrFail();
        $this->assertEquals($happened->timestamp, $c->occurred_at->timestamp);
        $this->assertTrue($c->isLate());
    }

    public function test_waktu_kejadian_di_masa_depan_atau_terlalu_lama_diganti_waktu_server(): void
    {
        $this->actingAs($this->cashierA)->postJson('/pos/cart/events', $this->cancelPayload(['occurred_at' => now()->addDay()->toIso8601String()]))->assertCreated();
        $this->postJson('/pos/cart/events', $this->cancelPayload(['occurred_at' => now()->subDays(30)->toIso8601String()]))->assertCreated();

        foreach (PosCancellation::all() as $c) {
            $this->assertTrue($c->occurred_at->diffInSeconds(now(), true) < 5);
        }
    }

    public function test_void_penjualan_dicatat_dengan_isi_barang_alasan_dan_perangkat(): void
    {
        $sale = $this->actingAs($this->cashierA)->postJson('/pos/sales', [
            'client_uuid' => (string) Str::uuid(), 'origin' => 'online', 'customer_id' => $this->customer->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 3]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 12000, 'status' => 'confirmed']],
        ])->assertCreated()->json('data');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])->withHeader('User-Agent', self::CHROME)
            ->postJson("/pos/sales/{$sale['id']}/void", ['reason' => 'Salah input'])->assertOk();

        $c = PosCancellation::where('type', PosCancellation::TYPE_SALE_VOID)->firstOrFail();
        $this->assertSame($sale['number'], $c->sale_number);
        $this->assertSame($sale['id'], $c->sale_id);
        $this->assertSame('Salah input', $c->reason);
        $this->assertSame('STR001 · Gerai A', $c->store_label);
        $this->assertSame('CR00001 · PELANGGAN UMUM', $c->customer_label);
        $this->assertSame('SKU-1', $c->items[0]['sku']);
        $this->assertEquals(3, $c->items[0]['qty']);
        $this->assertEquals(12000, $c->total);
        $this->assertSame('10.0.0.7', $c->ip_address);
    }

    public function test_pembatalan_tidak_valid_ditolak(): void
    {
        $this->actingAs($this->cashierA)->postJson('/pos/cart/events', $this->cancelPayload(['customer_id' => (string) Str::uuid()]))->assertUnprocessable();
        $this->postJson('/pos/cart/events', $this->cancelPayload(['items' => [['sku' => 'X', 'qty' => -1]]]))->assertUnprocessable();
        $this->assertSame(0, PosCancellation::count());
    }

    public function test_owner_melihat_semua_gerai_dan_dapat_memfilter(): void
    {
        $this->seedCancellation($this->storeA, $this->cashierA, ['customer_label' => 'CR00001 · BUDI']);
        $this->seedCancellation($this->storeB, $this->cashierB, ['customer_label' => 'CR00002 · SITI', 'type' => PosCancellation::TYPE_SALE_VOID, 'sale_number' => 'SAL/0001']);

        $this->actingAs($this->owner)->get(route('admin.cancellations.index'))
            ->assertOk()
            ->assertSee('Pembatalan Transaksi')
            ->assertSee('STR001 · Gerai A')->assertSee('STR002 · Gerai B')
            ->assertSee('BUDI')->assertSee('SITI')
            ->assertSee('10.0.0.9')->assertSee('Chrome 142 · Windows')
            ->assertSee('Barang X')
            ->assertSee('Void penjualan')->assertSee('Batal sebelum bayar');

        $this->get(route('admin.cancellations.index', ['store' => $this->storeB->id]))->assertOk()->assertSee('SITI')->assertDontSee('BUDI');
        $this->get(route('admin.cancellations.index', ['cashier' => $this->cashierA->id]))->assertOk()->assertSee('BUDI')->assertDontSee('SITI');
        $this->get(route('admin.cancellations.index', ['type' => 'sale_void']))->assertOk()->assertSee('SITI')->assertDontSee('BUDI');
        $this->get(route('admin.cancellations.index', ['q' => 'SAL/0001']))->assertOk()->assertSee('SITI')->assertDontSee('BUDI');
    }

    public function test_store_manager_hanya_melihat_gerainya(): void
    {
        $this->seedCancellation($this->storeA, $this->cashierA, ['customer_label' => 'CR00001 · BUDI']);
        $this->seedCancellation($this->storeB, $this->cashierB, ['customer_label' => 'CR00002 · SITI']);

        $this->actingAs($this->managerA)->get(route('admin.cancellations.index', ['store' => $this->storeB->id]))
            ->assertOk()
            ->assertSee('BUDI')
            ->assertDontSee('SITI');
    }

    public function test_kasir_tidak_dapat_membuka_laporan(): void
    {
        $this->actingAs($this->cashierA)->get(route('admin.cancellations.index'))->assertForbidden();
    }

    public function test_periode_menyaring_berdasarkan_tanggal_zona_waktu_perusahaan(): void
    {
        // 31 Okt 2026 17:30 UTC = 1 Nov 2026 00:30 WIB.
        $this->seedCancellation($this->storeA, $this->cashierA, ['customer_label' => 'CR1 · MALAM', 'occurred_at' => '2026-10-31 17:30:00']);
        $this->seedCancellation($this->storeA, $this->cashierA, ['customer_label' => 'CR2 · SIANG', 'occurred_at' => '2026-10-31 05:00:00']);

        $this->actingAs($this->owner)->get(route('admin.cancellations.index', ['from' => '2026-11-01', 'to' => '2026-11-01']))
            ->assertOk()
            ->assertSee('MALAM')
            ->assertSee('01/11/2026 00:30:00')
            ->assertDontSee('SIANG');
    }

    public function test_ekspor_excel_memuat_pembatalan_sesuai_filter(): void
    {
        $this->seedCancellation($this->storeA, $this->cashierA);

        $response = $this->actingAs($this->owner)->get(route('admin.cancellations.export', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));

        $response->assertOk();
        $this->assertStringContainsString('pembatalan_transaksi_', (string) $response->headers->get('content-disposition'));
    }

    public function test_laporan_dikelompokkan_per_gerai_dengan_ringkasan_di_judul(): void
    {
        $this->seedCancellation($this->storeA, $this->cashierA, ['total' => 5000]);
        $this->seedCancellation($this->storeA, $this->cashierA, ['total' => 7000, 'type' => PosCancellation::TYPE_SALE_VOID, 'sale_number' => 'SAL/0009']);
        $this->seedCancellation($this->storeB, $this->cashierB, ['total' => 1000]);

        $response = $this->actingAs($this->owner)->get(route('admin.cancellations.index'))->assertOk();

        $response->assertSeeInOrder(['STR001 · Gerai A', '2 pembatalan', 'Rp 12.000', '1 void', '1 batal sebelum bayar', 'STR002 · Gerai B', '1 pembatalan', 'Rp 1.000']);
        $this->assertSame(2, substr_count($response->getContent(), 'class="cancel-group"'));
        $response->assertSee('Buka semua')->assertSee('Tutup semua');
    }

    public function test_gerai_dengan_banyak_pembatalan_menampilkan_terbaru_dan_catatan_batas(): void
    {
        foreach (range(1, 55) as $i) {
            $this->seedCancellation($this->storeA, $this->cashierA, ['customer_label' => sprintf('CR%03d · PLG-%03d', $i, $i), 'occurred_at' => now()->subMinutes(60 - $i)]);
        }

        $response = $this->actingAs($this->owner)->get(route('admin.cancellations.index'))->assertOk();

        $response->assertSee('PLG-055')->assertDontSee('PLG-005');
        $response->assertSee('Menampilkan 50 terbaru dari 55');

        // Memilih satu gerai menampilkan lebih banyak baris per gerai.
        $this->get(route('admin.cancellations.index', ['store' => $this->storeA->id]))->assertOk()->assertSee('PLG-001')->assertDontSee('Menampilkan 50 terbaru');
    }

    public function test_pembatalan_tanpa_gerai_tercatat_masuk_kelompok_sendiri(): void
    {
        $this->seedCancellation($this->storeA, $this->cashierA);
        $this->seedCancellation($this->storeA, $this->cashierA, ['store_id' => null, 'store_label' => null, 'customer_label' => 'CR9 · TANPA GERAI']);

        $this->actingAs($this->owner)->get(route('admin.cancellations.index'))->assertOk()->assertSee('Gerai tidak diketahui')->assertSee('TANPA GERAI');
    }
}
