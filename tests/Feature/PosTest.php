<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\PaymentType;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\SaleDraft;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\User;
use App\Support\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $cashier;

    private Product $product;

    private PaymentType $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co',
            'ppn_enabled' => true,
            'ppn_rate' => 11,
            'ppn_inclusive' => false,
            'allow_negative_stock' => false,
            'return_window_days' => 30,
            'return_require_receipt' => true,
        ]);

        $this->store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'STR001',
            'name' => 'Gerai A',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);

        $this->cashier = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Kasir',
            'username' => 'kasir1',
            'role' => User::ROLE_CASHIER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'BEV', 'name' => 'Minuman', 'is_active' => true]);

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'product_group_id' => $group->id,
            'unit_id' => $unit->id,
            'sku' => 'SKU-1',
            'name' => 'Aqua',
            'base_price' => 4000,
            'track_stock' => true,
            'is_active' => true,
        ]);

        $priceList = PriceList::create([
            'company_id' => $this->company->id,
            'code' => 'DEFAULT',
            'name' => 'Default',
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->store->priceLists()->sync([$priceList->id => ['is_default' => true]]);

        $this->cash = PaymentType::create([
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'description' => 'Tunai',
            'order_number' => 1,
            'count_type' => 'summary',
            'is_offline_allowed' => true,
            'is_active' => true,
        ]);
        $this->cash->stores()->sync([$this->store->id]);

        StockService::record($this->store->id, $this->product->id, StockLedger::TYPE_OPENING, 10);
    }

    private function salePayload(array $overrides = []): array
    {
        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'origin' => 'online',
            'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 8880, 'status' => 'confirmed']],
        ], $overrides);
    }

    private function createBuyTwoGetOne(): Promotion
    {
        return Promotion::create([
            'company_id' => $this->company->id,
            'code' => 'B2G1',
            'name' => 'Beli 2 Gratis 1',
            'type' => 'buy_x_get_y',
            'product_id' => $this->product->id,
            'buy_qty' => 2,
            'get_qty' => 1,
            'is_active' => true,
        ]);
    }

    public function test_pos_shell_dapat_diakses_kasir(): void
    {
        $this->actingAs($this->cashier)->get('/pos')->assertOk();
    }

    public function test_bootstrap_mengembalikan_konteks_dan_tipe_pembayaran(): void
    {
        $res = $this->actingAs($this->cashier)->getJson('/pos/bootstrap')->assertOk();
        $res->assertJsonPath('store.code', 'STR001');
        $res->assertJsonPath('company.ppn_rate', 11);
        $this->assertCount(1, $res->json('payment_types'));
    }

    public function test_bootstrap_menampilkan_tanggal_bisnis_dan_tanggal_eod_yang_tertunda(): void
    {
        $this->travelTo(now('Asia/Jakarta')->subDay()->setTime(10, 0));
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload())->assertCreated();
        $yesterday = company_today();
        $this->travelBack();

        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('business.business_date', company_today())
            ->assertJsonPath('business.timezone', 'Asia/Jakarta')
            ->assertJsonPath('business.locked_date', $yesterday);
    }

    public function test_bootstrap_mengirim_pelanggan_default_dari_master_gerai(): void
    {
        $retail = Customer::create(['company_id' => $this->company->id, 'code' => 'C-RTL', 'name' => 'Pelanggan Retail', 'is_member' => false, 'is_active' => true]);
        $this->store->update(['default_customer_id' => $retail->id]);

        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('default_customer', ['id' => $retail->id, 'code' => 'C-RTL', 'name' => 'Pelanggan Retail']);
    }

    public function test_bootstrap_tanpa_pelanggan_default_bila_gerai_belum_mengaturnya(): void
    {
        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('default_customer', null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function storeBoundRoles(): array
    {
        return [
            'kasir' => [User::ROLE_CASHIER],
            'store manager' => [User::ROLE_STORE_MANAGER],
        ];
    }

    #[DataProvider('storeBoundRoles')]
    public function test_user_tanpa_gerai_ditolak_422_dan_tidak_memakai_gerai_pertama(string $role): void
    {
        $user = User::create(['company_id' => $this->company->id, 'store_id' => null, 'name' => 'Staf', 'username' => 'staf1', 'role' => $role, 'is_active' => true, 'password' => 'password']);

        $this->actingAs($user)->getJson('/pos/bootstrap')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Akun Anda belum ditugaskan ke gerai. Hubungi Owner.');

        $this->actingAs($user)->postJson('/pos/sales', $this->salePayload())->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    #[DataProvider('storeBoundRoles')]
    public function test_user_di_gerai_nonaktif_ditolak_422(string $role): void
    {
        $this->store->update(['is_active' => false]);
        $user = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Staf', 'username' => 'staf1', 'role' => $role, 'is_active' => true, 'password' => 'password']);

        $this->actingAs($user)->getJson('/pos/customers')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Gerai Anda sedang nonaktif. Hubungi Owner.');
    }

    public function test_owner_tanpa_gerai_tetap_memakai_gerai_pertama(): void
    {
        $owner = User::create(['company_id' => $this->company->id, 'store_id' => null, 'name' => 'Owner', 'username' => 'owner1', 'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password']);

        $this->actingAs($owner)->getJson('/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('store.code', 'STR001');
    }

    private function moveCashierToNewStore(): Store
    {
        $newStore = Store::create(['company_id' => $this->company->id, 'code' => 'STR002', 'name' => 'Gerai B', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $this->cashier->update(['store_id' => $newStore->id]);

        return $newStore;
    }

    public function test_penjualan_offline_dicatat_ke_gerai_asal_setelah_kasir_dipindah(): void
    {
        $newStore = $this->moveCashierToNewStore();

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload(['origin' => 'offline', 'store_id' => $this->store->id]))
            ->assertCreated();

        $this->assertSame($this->store->id, Sale::first()->store_id);
        $this->assertSame(8.0, StockService::balance($this->store->id, $this->product->id));
        $this->assertSame(0.0, StockService::balance($newStore->id, $this->product->id));
        $this->assertDatabaseHas('activity_logs', ['action' => 'pos.sale_origin_store']);
    }

    public function test_penjualan_offline_ke_gerai_asal_diterima_walau_gerai_kasir_nonaktif(): void
    {
        $this->store->update(['is_active' => false]);

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload(['origin' => 'offline', 'store_id' => $this->store->id]))
            ->assertCreated();

        $this->assertSame($this->store->id, Sale::first()->store_id);
    }

    public function test_penjualan_offline_ditolak_bila_gerai_asal_milik_perusahaan_lain(): void
    {
        $otherCompany = CompanyDetail::create(['name' => 'Lain Co']);
        $foreignStore = Store::create(['company_id' => $otherCompany->id, 'code' => 'LAIN', 'name' => 'Gerai Lain', 'pic_name' => 'PIC', 'currency' => 'IDR']);

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload(['origin' => 'offline', 'store_id' => $foreignStore->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_id');

        $this->assertSame(0, Sale::count());
    }

    public function test_penjualan_online_dari_layar_gerai_lama_ditolak_409(): void
    {
        $this->moveCashierToNewStore();

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload(['store_id' => $this->store->id]))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Penugasan gerai Anda berubah. Muat ulang POS sebelum melanjutkan.');

        $this->assertSame(0, Sale::count());
    }

    public function test_hold_gerai_lama_tidak_tampil_dan_tidak_bisa_dilanjutkan_setelah_pindah(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => (string) Str::uuid(),
            'payload' => ['cart' => []],
        ])->assertCreated();
        $draft = SaleDraft::first();

        $this->moveCashierToNewStore();

        $this->actingAs($this->cashier)->getJson('/pos/holds')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->cashier)->postJson("/pos/holds/{$draft->id}/consume")->assertStatus(409);
        $this->assertSame('held', $draft->fresh()->status);
    }

    public function test_bootstrap_meminta_konfirmasi_bila_eod_tertunda_dan_tidak_lagi_setelah_dikonfirmasi(): void
    {
        $this->travelTo(now('Asia/Jakarta')->subDay()->setTime(10, 0));
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload())->assertCreated();
        $yesterday = company_today();
        $this->travelBack();

        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('prompt.backdate_date', $yesterday)
            ->assertJsonPath('prompt.posting_date', $yesterday)
            ->assertJsonPath('business.posting_date', $yesterday);

        $this->actingAs($this->cashier)->postJson('/pos/session/acknowledge')->assertOk();

        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')
            ->assertJsonPath('prompt.backdate_date', null)
            ->assertJsonPath('prompt.posting_date', $yesterday);
    }

    public function test_login_baru_meminta_konfirmasi_tanggal_bisnis_lagi(): void
    {
        $this->travelTo(now('Asia/Jakarta')->subDay()->setTime(10, 0));
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload())->assertCreated();
        $yesterday = company_today();
        $this->travelBack();
        $this->actingAs($this->cashier)->postJson('/pos/session/acknowledge')->assertOk();
        $this->post('/logout');

        $this->post('/login', ['username' => 'kasir1', 'password' => 'password'])->assertRedirect();

        $this->getJson('/pos/bootstrap')->assertJsonPath('prompt.backdate_date', $yesterday);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function greetingTimes(): array
    {
        return [
            '05.59 malam' => ['05:59', 'Selamat malam'],
            '06.00 pagi' => ['06:00', 'Selamat pagi'],
            '09.59 pagi' => ['09:59', 'Selamat pagi'],
            '10.00 siang' => ['10:00', 'Selamat siang'],
            '14.59 siang' => ['14:59', 'Selamat siang'],
            '15.00 sore' => ['15:00', 'Selamat sore'],
            '17.59 sore' => ['17:59', 'Selamat sore'],
            '18.00 malam' => ['18:00', 'Selamat malam'],
            '00.00 malam' => ['00:00', 'Selamat malam'],
        ];
    }

    #[DataProvider('greetingTimes')]
    public function test_kasir_disapa_sesuai_jam_login_dan_sapaan_hilang_setelah_dikonfirmasi(string $time, string $greeting): void
    {
        $this->travelTo(Carbon::parse("2026-11-05 {$time}", 'Asia/Jakarta'));

        $this->post('/login', ['username' => 'kasir1', 'password' => 'password'])->assertRedirect();

        $this->getJson('/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('prompt.greeting', $greeting)
            ->assertJsonPath('prompt.posting_date', '2026-11-05');

        $this->postJson('/pos/session/acknowledge')->assertOk()->assertJsonPath('data.greeting', null);
        $this->getJson('/pos/bootstrap')->assertJsonPath('prompt.greeting', null);
    }

    public function test_store_manager_tidak_mendapat_sapaan_kasir(): void
    {
        User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Manager', 'username' => 'manager1', 'role' => User::ROLE_STORE_MANAGER, 'is_active' => true, 'password' => 'password']);

        $this->post('/login', ['username' => 'manager1', 'password' => 'password'])->assertRedirect();

        $this->getJson('/pos/bootstrap')->assertOk()->assertJsonPath('prompt.greeting', null);
    }

    public function test_pencarian_produk_tidak_membedakan_huruf_besar_kecil(): void
    {
        $res = $this->actingAs($this->cashier)->getJson('/pos/products?q=aqua')->assertOk();

        $res->assertJsonPath('data.0.name', 'Aqua');
    }

    public function test_penjualan_menghitung_pajak_dan_mengurangi_stok(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.subtotal', 8000)
            ->assertJsonPath('data.tax_total', 880)
            ->assertJsonPath('data.total', 8880);

        $sale = Sale::first();
        $this->assertNotNull($sale->number);
        $this->assertSame(8.0, StockService::balance($this->store->id, $this->product->id));
        $this->assertDatabaseHas('stock_ledger', ['ref_id' => $sale->id, 'type' => 'sale', 'qty' => -2]);
    }

    public function test_kode_tax_group_dicatat_per_baris_dan_ppn_nol_persen_tetap_ppn(): void
    {
        $ppn11 = TaxGroup::factory()->create(['company_id' => $this->company->id, 'code' => 'PPNK-11', 'rate' => 11]);
        $this->company->update(['tax_group_id' => $ppn11->id, 'ppn_rate' => 11]);

        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload())->assertCreated();
        $this->assertDatabaseHas('sale_lines', ['tax_group_code' => 'PPNK-11', 'tax_rate' => 11, 'tax_amount' => 880]);

        $ppn0 = TaxGroup::factory()->create(['company_id' => $this->company->id, 'code' => 'PPNK-0', 'rate' => 0]);
        $this->company->update(['tax_group_id' => $ppn0->id, 'ppn_rate' => 0]);

        $this->postJson('/pos/sales', $this->salePayload(['payments' => [['payment_type_id' => $this->cash->id, 'amount' => 8000, 'status' => 'confirmed']]]))
            ->assertCreated()
            ->assertJsonPath('data.tax_total', 0)
            ->assertJsonPath('data.total', 8000);
        $this->assertDatabaseHas('sale_lines', ['tax_group_code' => 'PPNK-0', 'tax_rate' => 0, 'tax_amount' => 0]);

        $this->getJson('/pos/bootstrap')->assertJsonPath('company.tax_group_code', 'PPNK-0');
    }

    public function test_idempotensi_client_uuid_mencegah_transaksi_ganda(): void
    {
        $payload = $this->salePayload();

        $first = $this->actingAs($this->cashier)->postJson('/pos/sales', $payload)->assertStatus(201)->json('data.id');
        $second = $this->actingAs($this->cashier)->postJson('/pos/sales', $payload)->assertStatus(201)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Sale::count());
        $this->assertSame(8.0, StockService::balance($this->store->id, $this->product->id));
    }

    public function test_penjualan_melebihi_stok_ditolak(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload([
                'lines' => [['product_id' => $this->product->id, 'qty' => 100]],
            ]))
            ->assertStatus(422);

        $this->assertSame(10.0, StockService::balance($this->store->id, $this->product->id));
    }

    public function test_retur_mengembalikan_stok(): void
    {
        $saleId = $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload())->json('data.id');
        $sale = Sale::with('lines')->find($saleId);
        $line = $sale->lines->first();

        $this->actingAs($this->cashier)
            ->postJson('/pos/returns', [
                'sale_id' => $sale->id,
                'refund_method' => 'cash',
                'lines' => [['sale_line_id' => $line->id, 'qty' => 1]],
            ])
            ->assertStatus(201);

        $this->assertSame(9.0, StockService::balance($this->store->id, $this->product->id));
    }

    public function test_retur_mengembalikan_harga_bersih_setelah_promosi_termasuk_ppn(): void
    {
        $this->createBuyTwoGetOne();

        // 7 × 4.000 = 28.000, gratis 2 → diskon 8.000, DPP 20.000, PPN 2.200, total baris 22.200.
        $saleId = $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload([
                'lines' => [['product_id' => $this->product->id, 'qty' => 7]],
                'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 22200, 'status' => 'confirmed']],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.total', 22200)
            ->json('data.id');
        $line = Sale::with('lines')->find($saleId)->lines->first();

        $returnLine = fn (float $qty) => $this->actingAs($this->cashier)
            ->postJson('/pos/returns', [
                'sale_id' => $saleId,
                'refund_method' => 'cash',
                'lines' => [['sale_line_id' => $line->id, 'qty' => $qty]],
            ])
            ->assertStatus(201);

        $returnLine(3)->assertJsonPath('data.total', 9514.29);
        $returnLine(4)->assertJsonPath('data.total', 12685.71);

        $this->assertDatabaseHas('sales_return_lines', ['sale_line_id' => $line->id, 'qty' => 3, 'unit_price' => 3171.43]);
        $this->assertSame(10.0, StockService::balance($this->store->id, $this->product->id));
    }

    public function test_void_mengembalikan_stok(): void
    {
        $saleId = $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload())->json('data.id');
        $sale = Sale::find($saleId);

        $this->actingAs($this->cashier)
            ->postJson("/pos/sales/{$sale->id}/void", ['reason' => 'salah input'])
            ->assertOk();

        $this->assertSame('void', $sale->refresh()->status);
        $this->assertSame(10.0, StockService::balance($this->store->id, $this->product->id));
    }

    public function test_daftar_promosi_kasir_berisi_promosi_aktif_dan_yang_akan_datang(): void
    {
        $promotion = $this->createBuyTwoGetOne();
        $promotion->update(['ends_at' => now()->addDay()]);

        foreach ([
            'SOON' => ['starts_at' => now()->addDay()],
            'ENDED' => ['ends_at' => now()->subMinute()],
            'OFF' => ['is_active' => false],
        ] as $code => $attributes) {
            Promotion::create($attributes + [
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => $code,
                'type' => 'discount_percent',
                'value' => 10,
                'is_active' => true,
            ]);
        }

        $response = $this->actingAs($this->cashier)->getJson('/pos/promotions')->assertOk();

        $this->assertEqualsCanonicalizing(['B2G1', 'SOON'], $response->json('data.*.code'));

        $bonus = collect($response->json('data'))->firstWhere('code', 'B2G1');
        $this->assertSame(2, $bonus['buy_qty']);
        $this->assertSame($this->product->id, $bonus['bonus_product']['id']);
        $this->assertEquals(4000, $bonus['bonus_product']['price']);
    }

    public function test_penjualan_online_menerapkan_promosi_dari_server(): void
    {
        $promotion = $this->createBuyTwoGetOne();

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload([
                'lines' => [['product_id' => $this->product->id, 'qty' => 3]],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.subtotal', 8000)
            ->assertJsonPath('data.discount_total', 4000)
            ->assertJsonPath('data.tax_total', 880)
            ->assertJsonPath('data.total', 8880)
            ->assertJsonPath('data.lines.0.discount', 4000)
            ->assertJsonPath('data.lines.0.promotion_name', 'Beli 2 Gratis 1');

        $this->assertDatabaseHas('sale_lines', [
            'promotion_id' => $promotion->id,
            'promotion_name_snapshot' => 'Beli 2 Gratis 1',
            'promotion_discount' => 4000,
        ]);
        $this->assertSame(7.0, StockService::balance($this->store->id, $this->product->id));
    }

    public function test_penjualan_online_dengan_promosi_kasir_yang_sesuai_diterima(): void
    {
        $promotion = $this->createBuyTwoGetOne();

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload([
                'lines' => [['product_id' => $this->product->id, 'qty' => 3, 'promotion_id' => $promotion->id, 'discount' => 4000]],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.total', 8880);
    }

    public function test_penjualan_online_dengan_promosi_kasir_berbeda_ditolak(): void
    {
        $promotion = $this->createBuyTwoGetOne();

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload([
                'lines' => [['product_id' => $this->product->id, 'qty' => 3, 'promotion_id' => null, 'discount' => 0]],
            ]))
            ->assertStatus(409)
            ->assertJsonPath('lines.0.promotion_id', $promotion->id)
            ->assertJsonPath('lines.0.promotion_discount', 4000);

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(10.0, StockService::balance($this->store->id, $this->product->id));
    }

    public function test_penjualan_offline_dengan_promosi_berbeda_tetap_dicatat_dan_ditandai_review(): void
    {
        $this->createBuyTwoGetOne();

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload([
                'origin' => 'offline',
                'lines' => [['product_id' => $this->product->id, 'qty' => 3, 'promotion_id' => null, 'discount' => 0]],
                'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 13320, 'status' => 'confirmed']],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.discount_total', 0)
            ->assertJsonPath('data.total', 13320)
            ->assertJsonPath('data.stock_review_status', 'pending');

        $this->assertSame('Promosi berbeda dengan perhitungan server untuk SKU-1.', Sale::first()->review_reason);
    }

    public function test_penjualan_offline_dengan_promosi_sesuai_tidak_perlu_review(): void
    {
        $promotion = $this->createBuyTwoGetOne();

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload([
                'origin' => 'offline',
                'lines' => [['product_id' => $this->product->id, 'qty' => 3, 'promotion_id' => $promotion->id, 'discount' => 4000]],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.discount_total', 4000);

        $this->assertSame('none', Sale::first()->stock_review_status);
    }

    public function test_promosi_milik_perusahaan_lain_ditolak(): void
    {
        $other = CompanyDetail::create(['name' => 'Other Co']);
        $foreign = Promotion::create([
            'company_id' => $other->id,
            'code' => 'X',
            'name' => 'Asing',
            'type' => 'discount_percent',
            'value' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload([
                'lines' => [['product_id' => $this->product->id, 'qty' => 2, 'promotion_id' => $foreign->id, 'discount' => 4000]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.promotion_id');
    }

    public function test_offline_negative_stock_ditandai_review(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload([
                'origin' => 'offline',
                'lines' => [['product_id' => $this->product->id, 'qty' => 15]],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.stock_review_status', 'pending');
    }

    public function test_hold_disimpan_dan_muncul_di_daftar(): void
    {
        $uuid = (string) Str::uuid();

        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => $uuid,
            'payload' => ['cart' => [['id' => $this->product->id, 'qty' => 1]], 'delivery' => ['enabled' => false]],
        ])->assertStatus(201);

        $this->actingAs($this->cashier)->getJson('/pos/holds')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client_uuid', $uuid);
    }

    public function test_hold_tidak_mengurangi_stok_dan_melanjutkan_hold_mengembalikan_stok_terkini(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => (string) Str::uuid(),
            'payload' => ['cart' => [['id' => $this->product->id, 'qty' => 8]], 'delivery' => ['enabled' => false]],
        ])->assertStatus(201);

        $this->assertSame(10.0, StockService::balance($this->store->id, $this->product->id));

        // Stok terjual sebagian selagi transaksi ditahan.
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload(['lines' => [['product_id' => $this->product->id, 'qty' => 7]], 'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 31080, 'status' => 'confirmed']]]))->assertCreated();

        $draft = SaleDraft::first();
        $this->actingAs($this->cashier)->postJson("/pos/holds/{$draft->id}/consume")
            ->assertOk()
            ->assertJsonPath("data.stock.{$this->product->id}", 3);
    }

    public function test_hold_tidak_tampil_untuk_kasir_lain(): void
    {
        $other = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Kasir Lain',
            'username' => 'kasir2',
            'role' => User::ROLE_CASHIER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => (string) Str::uuid(),
            'payload' => ['cart' => [['id' => $this->product->id, 'qty' => 1]]],
        ])->assertStatus(201);

        $this->actingAs($other)->getJson('/pos/holds')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hold_yang_dilanjutkan_hilang_dari_daftar(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => (string) Str::uuid(),
            'payload' => ['cart' => [['id' => $this->product->id, 'qty' => 1]]],
        ])->assertStatus(201);

        $draft = SaleDraft::first();

        $this->actingAs($this->cashier)->getJson('/pos/holds')->assertJsonCount(1, 'data');

        $this->actingAs($this->cashier)
            ->postJson("/pos/holds/{$draft->id}/consume")
            ->assertOk();

        $this->actingAs($this->cashier)->getJson('/pos/holds')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hold_tidak_dapat_dilanjutkan_dua_kali(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => (string) Str::uuid(),
            'payload' => ['cart' => [['id' => $this->product->id, 'qty' => 1]]],
        ])->assertStatus(201);

        $draft = SaleDraft::first();

        $this->actingAs($this->cashier)->postJson("/pos/holds/{$draft->id}/consume")->assertOk();
        $this->actingAs($this->cashier)->postJson("/pos/holds/{$draft->id}/consume")->assertStatus(409);
    }

    public function test_hold_milik_kasir_lain_tidak_dapat_ditimpa(): void
    {
        $uuid = (string) Str::uuid();
        $other = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Kasir Lain',
            'username' => 'kasir2',
            'role' => User::ROLE_CASHIER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => $uuid,
            'payload' => ['cart' => [['id' => $this->product->id, 'qty' => 1]]],
        ])->assertStatus(201);

        $this->actingAs($other)->postJson('/pos/holds', [
            'client_uuid' => $uuid,
            'payload' => ['cart' => []],
        ])->assertStatus(409);

        $draft = SaleDraft::where('client_uuid', $uuid)->first();
        $this->assertSame($this->cashier->id, $draft->cashier_id);
        $this->assertCount(1, $draft->payload['cart']);
    }

    public function test_hold_kasir_lain_hanya_dapat_dibatalkan_manager(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => (string) Str::uuid(),
            'payload' => ['cart' => [['id' => $this->product->id, 'qty' => 1]]],
        ])->assertStatus(201);

        $draft = SaleDraft::first();
        $makeUser = fn (string $username, string $role) => User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => $username,
            'username' => $username,
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->actingAs($makeUser('kasir2', User::ROLE_CASHIER))
            ->deleteJson("/pos/holds/{$draft->id}")
            ->assertForbidden();

        $this->actingAs($makeUser('manager1', User::ROLE_STORE_MANAGER))
            ->deleteJson("/pos/holds/{$draft->id}")
            ->assertOk();

        $this->assertSame('cancelled', $draft->refresh()->status);
    }

    public function test_hold_yang_dilepas_kembali_ke_daftar(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => (string) Str::uuid(),
            'payload' => ['cart' => [['id' => $this->product->id, 'qty' => 1]]],
        ])->assertStatus(201);

        $draft = SaleDraft::first();

        $this->actingAs($this->cashier)->postJson("/pos/holds/{$draft->id}/consume")->assertOk();
        $this->assertSame('resumed', $draft->refresh()->status);

        $this->actingAs($this->cashier)->postJson("/pos/holds/{$draft->id}/release")->assertOk();

        $this->actingAs($this->cashier)->getJson('/pos/holds')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_penjualan_dari_hold_memfinalkan_hold(): void
    {
        $uuid = (string) Str::uuid();

        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => $uuid,
            'payload' => ['cart' => [['id' => $this->product->id, 'qty' => 2]]],
        ])->assertStatus(201);

        $draft = SaleDraft::first();
        $this->actingAs($this->cashier)->postJson("/pos/holds/{$draft->id}/consume")->assertOk();

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', $this->salePayload(['hold_client_uuid' => $uuid]))
            ->assertStatus(201);

        $this->assertSame('finalized', $draft->refresh()->status);

        $this->actingAs($this->cashier)->postJson('/pos/holds', [
            'client_uuid' => $uuid,
            'payload' => ['cart' => []],
        ])->assertStatus(409);
    }
}
