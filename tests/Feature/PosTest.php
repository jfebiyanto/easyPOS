<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\CreditCard;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PaymentType;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\SaleDraft;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\StoreClosure;
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

    public function test_tipe_pembayaran_yang_dipetakan_ke_kartu_kredit_meminta_kode_approval_dan_menyimpannya(): void
    {
        $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
        $edc = PaymentType::create([
            'company_id' => $this->company->id, 'code' => 'EDC', 'order_number' => 2, 'count_type' => 'summary',
            'is_active' => true, 'credit_card_id' => $card->id,
        ]);
        $edc->stores()->sync([$this->store->id]);

        $types = collect($this->actingAs($this->cashier)->getJson('/pos/bootstrap')->assertOk()->json('payment_types'))->keyBy('code');
        $this->assertTrue($types['EDC']['requires_approval']);
        $this->assertFalse($types['CASH']['requires_approval']);

        $this->postJson('/pos/sales', $this->salePayload(['payments' => [
            ['payment_type_id' => $edc->id, 'amount' => 8880, 'status' => 'confirmed', 'approval_code' => ' 123456 '],
        ]]))->assertCreated()->assertJsonPath('data.payments.0.approval_code', '123456');

        $this->assertSame('123456', Sale::firstOrFail()->payments()->value('approval_code'));
    }

    public function test_kode_approval_dibatasi_50_karakter(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload(['payments' => [
            ['payment_type_id' => $this->cash->id, 'amount' => 8880, 'status' => 'confirmed', 'approval_code' => str_repeat('9', 51)],
        ]]))->assertUnprocessable();
    }

    private function sapVolumePromotion(array $overrides = []): Promotion
    {
        return Promotion::create(array_merge([
            'company_id' => $this->company->id, 'code' => 'PV-1', 'name' => 'Period & Volume SKU-1', 'type' => Promotion::TYPE_VOLUME_PRICE,
            'source' => Promotion::SOURCE_SAP, 'sap_ref' => 'PV|SKU-1|*1|DEFAULT|0', 'product_id' => $this->product->id, 'value' => 0,
            'price_list_code' => 'DEFAULT', 'tiers' => [['min_qty' => 1, 'price' => 4000], ['min_qty' => 5, 'price' => 3800]],
            'is_active' => true,
        ], $overrides));
    }

    public function test_diskon_volume_sap_dihitung_di_penjualan_dan_dicatat_di_baris(): void
    {
        $promotion = $this->sapVolumePromotion();

        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload([
            'lines' => [['product_id' => $this->product->id, 'qty' => 5, 'promotion_id' => $promotion->id, 'discount' => 1000]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 30000, 'status' => 'confirmed']],
        ]))->assertCreated();

        $line = Sale::firstOrFail()->lines()->firstOrFail();
        $this->assertSame($promotion->id, $line->promotion_id);
        $this->assertEquals(1000, $line->discount);
    }

    public function test_diskon_sap_terikat_price_list_tidak_berlaku_bila_price_list_gerai_berbeda(): void
    {
        $this->sapVolumePromotion(['price_list_code' => '99']);

        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload([
            'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 30000, 'status' => 'confirmed']],
        ]))->assertCreated();

        $this->assertEquals(0, Sale::firstOrFail()->lines()->firstOrFail()->discount);
    }

    public function test_diskon_customer_group_sap_hanya_untuk_pelanggan_di_group_itu(): void
    {
        $group = CustomerGroup::create(['company_id' => $this->company->id, 'code' => '102', 'name' => 'RETAIL', 'is_active' => true]);
        $member = Customer::create(['company_id' => $this->company->id, 'code' => 'CR1', 'name' => 'Anggota', 'customer_group_id' => $group->id, 'is_active' => true]);
        $other = Customer::create(['company_id' => $this->company->id, 'code' => 'CR2', 'name' => 'Lain', 'is_active' => true]);
        $promotion = Promotion::create([
            'company_id' => $this->company->id, 'code' => 'DG-1', 'name' => 'Discount Group #1', 'type' => 'discount_percent', 'value' => 10,
            'source' => Promotion::SOURCE_SAP, 'sap_ref' => 'DG|1|0', 'customer_scope' => Promotion::CUSTOMER_GROUP, 'customer_ref' => '102', 'is_active' => true,
        ]);

        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload([
            'customer_id' => $member->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 2, 'promotion_id' => $promotion->id, 'discount' => 800]],
        ]))->assertCreated();
        $this->assertEquals(800, Sale::firstOrFail()->lines()->firstOrFail()->discount);

        // Pelanggan di luar group: klien yang mengklaim diskon ditolak (409) karena server tidak menghitungnya.
        $this->postJson('/pos/sales', $this->salePayload([
            'customer_id' => $other->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 2, 'promotion_id' => $promotion->id, 'discount' => 800]],
        ]))->assertStatus(409);
    }

    public function test_hierarki_promosi_perusahaan_menentukan_pemenang_di_server(): void
    {
        $volume = $this->sapVolumePromotion();
        $special = Promotion::create(['company_id' => $this->company->id, 'code' => 'SP-1', 'name' => 'Harga Khusus', 'type' => 'special_price', 'value' => 3000, 'product_id' => $this->product->id, 'is_active' => true]);
        $line = fn (Promotion $winner, float $discount) => [['product_id' => $this->product->id, 'qty' => 5, 'promotion_id' => $winner->id, 'discount' => $discount]];
        $payments = [['payment_type_id' => $this->cash->id, 'amount' => 30000, 'status' => 'confirmed']];

        // Bawaan: SAP Period & Volume di atas Harga Khusus easyPOS, walau potongannya lebih kecil (1.000 vs 5.000).
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload(['lines' => $line($volume, 1000), 'payments' => $payments]))->assertCreated();

        $this->company->update(['promotion_hierarchy' => ['special_price', 'sap_period_volume']]);
        $this->postJson('/pos/sales', $this->salePayload(['lines' => $line($special, 5000), 'payments' => $payments]))->assertCreated();

        $this->assertEquals([1000.0, 5000.0], Sale::orderBy('created_at')->get()->map(fn (Sale $s) => (float) $s->lines()->first()->discount)->all());
    }

    public function test_endpoint_promosi_mengirim_hierarki_dan_price_list_gerai(): void
    {
        $this->sapVolumePromotion();

        $res = $this->actingAs($this->cashier)->getJson('/pos/promotions')->assertOk();
        $res->assertJsonPath('meta.price_list_code', 'DEFAULT');
        $res->assertJsonPath('meta.hierarchy.0', 'sap_period_volume');
        $res->assertJsonPath('data.0.tiers.1.price', 3800);
        $res->assertJsonPath('data.0.source', 'sap');
    }

    public function test_bootstrap_mengirim_group_pelanggan_default_untuk_diskon_customer_group(): void
    {
        $group = CustomerGroup::create(['company_id' => $this->company->id, 'code' => '102', 'name' => 'RETAIL', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => 'CR00001', 'name' => 'UMUM', 'customer_group_id' => $group->id, 'is_active' => true]);
        $this->store->update(['default_customer_id' => $customer->id]);

        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')->assertOk()->assertJsonPath('default_customer.group_code', '102');
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

    public function test_daftar_transaksi_hanya_gerai_kasir_pada_business_date_aktif(): void
    {
        $retail = Customer::create(['company_id' => $this->company->id, 'code' => 'C-RTL', 'name' => 'Pelanggan Retail', 'is_member' => false, 'is_active' => true]);
        $this->store->update(['default_customer_id' => $retail->id]);

        $payload = $this->salePayload();
        $this->actingAs($this->cashier)->postJson('/pos/sales', $payload)->assertCreated();
        $today = Sale::where('client_uuid', $payload['client_uuid'])->first();

        $otherDay = $this->salePayload();
        $this->actingAs($this->cashier)->postJson('/pos/sales', $otherDay)->assertCreated();
        $yesterdaySale = Sale::where('client_uuid', $otherDay['client_uuid'])->first();
        $yesterdaySale->update(['business_date' => now('Asia/Jakarta')->subDay()->toDateString()]);
        StoreClosure::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'business_date' => now('Asia/Jakarta')->subDay()->toDateString(), 'closed_at' => now()]);

        $otherStore = Store::create(['company_id' => $this->company->id, 'code' => 'STR002', 'name' => 'Gerai B', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $foreign = Sale::create(['company_id' => $this->company->id, 'store_id' => $otherStore->id, 'number' => 'S-LAIN', 'client_uuid' => (string) Str::uuid(), 'business_date' => company_today(), 'sold_at' => now(), 'cashier_id' => $this->cashier->id]);

        $res = $this->actingAs($this->cashier)->getJson('/pos/sales')->assertOk();

        $res->assertJsonPath('business_date', company_today());
        $this->assertSame([$today->id], collect($res->json('data'))->pluck('id')->all());
        $res->assertJsonPath('data.0.number', $today->number)
            ->assertJsonPath('data.0.customer', ['code' => 'C-RTL', 'name' => 'Pelanggan Retail'])
            ->assertJsonPath('data.0.total', 8880)
            ->assertJsonPath('data.0.tax_total', 880);
        $this->assertNotContains($foreign->id, collect($res->json('data'))->pluck('id')->all());
    }

    public function test_daftar_transaksi_memakai_tanggal_posting_saat_eod_tertunda(): void
    {
        $this->travelTo(now('Asia/Jakarta')->subDay()->setTime(10, 0));
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload())->assertCreated();
        $yesterday = company_today();
        $this->travelBack();

        $this->actingAs($this->cashier)->getJson('/pos/sales')
            ->assertOk()
            ->assertJsonPath('business_date', $yesterday)
            ->assertJsonCount(1, 'data');
    }

    public function test_daftar_transaksi_ditolak_untuk_kasir_tanpa_gerai(): void
    {
        $user = User::create(['company_id' => $this->company->id, 'store_id' => null, 'name' => 'Staf', 'username' => 'staf1', 'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password']);

        $this->actingAs($user)->getJson('/pos/sales')->assertStatus(422);
    }

    public function test_bootstrap_mengirim_pelanggan_default_dari_master_gerai(): void
    {
        $retail = Customer::create(['company_id' => $this->company->id, 'code' => 'C-RTL', 'name' => 'Pelanggan Retail', 'is_member' => false, 'is_active' => true]);
        $this->store->update(['default_customer_id' => $retail->id]);

        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')
            ->assertOk()
            ->assertJsonPath('default_customer', ['id' => $retail->id, 'code' => 'C-RTL', 'name' => 'Pelanggan Retail', 'group_code' => null]);
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
