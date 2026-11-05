<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Currency;
use App\Models\PaymentType;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\StoreClosure;
use App\Models\Unit;
use App\Models\User;
use App\Support\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EodTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    private User $cashier;

    private Product $product;

    private PaymentType $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'ppn_enabled' => false, 'ppn_rate' => 0, 'timezone' => 'Asia/Jakarta', 'allow_negative_stock' => false]);
        $this->store = Store::create(['company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $this->owner = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Owner', 'username' => 'owner1', 'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password']);
        $this->cashier = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Kasir', 'username' => 'kasir1', 'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password']);

        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'BEV', 'name' => 'Minuman', 'is_active' => true]);
        $this->product = Product::create(['company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id, 'sku' => 'SKU-1', 'name' => 'Aqua', 'base_price' => 10000, 'track_stock' => true, 'is_active' => true]);

        $priceList = PriceList::create(['company_id' => $this->company->id, 'code' => 'DEFAULT', 'name' => 'Default', 'is_default' => true, 'is_active' => true]);
        $this->store->priceLists()->sync([$priceList->id => ['is_default' => true]]);

        $this->cash = PaymentType::create(['company_id' => $this->company->id, 'code' => 'CASH', 'description' => 'Tunai', 'order_number' => 1, 'count_type' => 'summary', 'is_active' => true]);
        $this->cash->stores()->sync([$this->store->id]);

        $idr = Currency::create(['company_id' => $this->company->id, 'code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);
        $idr->denominations()->create(['value' => 10000, 'is_active' => true]);

        StockService::record($this->store->id, $this->product->id, StockLedger::TYPE_OPENING, 100);
    }

    private function sell(float $qty = 1): Sale
    {
        $this->actingAs($this->cashier)->postJson('/pos/sales', [
            'client_uuid' => (string) Str::uuid(),
            'origin' => 'online',
            'lines' => [['product_id' => $this->product->id, 'qty' => $qty]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 10000 * $qty, 'status' => 'confirmed']],
        ])->assertStatus(201);

        return Sale::latest('created_at')->first();
    }

    public function test_halaman_eod_dapat_diakses(): void
    {
        $this->actingAs($this->owner)->get(route('admin.eod.index'))->assertOk();
    }

    public function test_tutup_toko_menghitung_kas_dan_selisih(): void
    {
        $this->sell(1);

        $this->actingAs($this->owner)->post(route('admin.eod.store'), [
            'store_id' => $this->store->id,
            'business_date' => company_today(),
            'denominations' => [10000 => 1],
        ])->assertRedirect();

        $closure = StoreClosure::first();
        $this->assertNotNull($closure);
        $this->assertSame(10000.0, (float) $closure->cash_expected);
        $this->assertSame(10000.0, (float) $closure->cash_counted);
        $this->assertSame(0.0, (float) $closure->cash_variance);
    }

    /**
     * @return array<string, mixed>
     */
    private function salePayload(string $origin = 'online'): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'origin' => $origin,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 10000, 'status' => 'confirmed']],
        ];
    }

    public function test_transaksi_ditolak_409_sampai_kasir_mengonfirmasi_pencatatan_ke_tanggal_eod_tertunda(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $this->sell(1)->update(['business_date' => $yesterday]);

        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->salePayload())->assertStatus(409);

        $this->assertSame(1, Sale::count());
    }

    public function test_setelah_konfirmasi_transaksi_hari_ini_dicatat_sebagai_tanggal_eod_tertunda(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $this->sell(1)->update(['business_date' => $yesterday]);

        $this->actingAs($this->cashier)->postJson('/pos/session/acknowledge')
            ->assertOk()
            ->assertJsonPath('data.backdate_date', null)
            ->assertJsonPath('data.posting_date', $yesterday);

        $payload = $this->salePayload();
        $this->actingAs($this->cashier)->postJson('/pos/sales', $payload)->assertStatus(201);

        $this->assertSame($yesterday, Sale::where('client_uuid', $payload['client_uuid'])->first()->business_date->toDateString());
    }

    public function test_transaksi_offline_tidak_perlu_konfirmasi_dan_dicatat_ke_tanggal_eod_tertunda(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $this->sell(1)->update(['business_date' => $yesterday]);

        $payload = $this->salePayload('offline');
        $this->actingAs($this->cashier)->postJson('/pos/sales', $payload)->assertStatus(201);

        $this->assertSame($yesterday, Sale::where('client_uuid', $payload['client_uuid'])->first()->business_date->toDateString());
    }

    public function test_setelah_eod_tertunda_selesai_transaksi_kembali_dicatat_ke_hari_ini(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $this->sell(1)->update(['business_date' => $yesterday]);

        $this->actingAs($this->owner)->post(route('admin.eod.store'), [
            'store_id' => $this->store->id,
            'business_date' => $yesterday,
            'denominations' => [10000 => 1],
        ])->assertRedirect();

        $payload = $this->salePayload();
        $this->actingAs($this->cashier)->postJson('/pos/sales', $payload)->assertStatus(201);

        $this->assertSame(company_today(), Sale::where('client_uuid', $payload['client_uuid'])->first()->business_date->toDateString());
    }

    public function test_beberapa_hari_belum_eod_dicatat_ke_tanggal_tertua(): void
    {
        $oldest = now()->subDays(2)->toDateString();
        $this->sell(1)->update(['business_date' => $oldest]);
        $this->actingAs($this->cashier)->postJson('/pos/session/acknowledge')->assertOk();
        $this->sell(1)->update(['business_date' => now()->subDay()->toDateString()]);

        $payload = $this->salePayload();
        $this->actingAs($this->cashier)->postJson('/pos/sales', $payload)->assertStatus(201);

        $this->assertSame($oldest, Sale::where('client_uuid', $payload['client_uuid'])->first()->business_date->toDateString());
    }

    private function close(string $date): void
    {
        $this->actingAs($this->owner)->post(route('admin.eod.store'), [
            'store_id' => $this->store->id,
            'business_date' => $date,
            'denominations' => [10000 => 1],
        ])->assertSessionHasNoErrors();
    }

    private function reopen(string $date, ?string $reason)
    {
        return $this->actingAs($this->owner)->post(route('admin.eod.reopen'), [
            'store_id' => $this->store->id,
            'business_date' => $date,
            'reopen_reason' => $reason,
        ]);
    }

    private function postOnlineSale()
    {
        return $this->actingAs($this->cashier)->postJson('/pos/sales', [
            'client_uuid' => (string) Str::uuid(),
            'origin' => 'online',
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 10000, 'status' => 'confirmed']],
        ]);
    }

    public function test_transaksi_terkunci_setelah_eod_dan_terbuka_kembali_setelah_eod_dibuka_ulang(): void
    {
        $this->sell(1);
        $this->close(company_today());

        $this->postOnlineSale()->assertStatus(422);

        $this->reopen(company_today(), 'Ada transaksi susulan')->assertSessionHas('success');

        $closure = StoreClosure::first();
        $this->assertNull($closure->closed_at);
        $this->assertSame('Ada transaksi susulan', $closure->reopen_reason);
        $this->assertSame($this->owner->id, $closure->reopened_by);
        $this->postOnlineSale()->assertStatus(201);
    }

    public function test_buka_ulang_eod_wajib_alasan(): void
    {
        $this->sell(1);
        $this->close(company_today());

        $this->reopen(company_today(), '')->assertSessionHasErrors('reopen_reason');

        $this->assertNotNull(StoreClosure::first()->closed_at);
    }

    public function test_eod_tanggal_lalu_tidak_dapat_dibuka_ulang(): void
    {
        $yesterday = now(company_timezone())->subDay()->toDateString();
        $this->sell(1)->update(['business_date' => $yesterday]);
        $this->close($yesterday);

        $this->reopen($yesterday, 'Koreksi kas')->assertSessionHas('error');

        $this->assertNotNull(StoreClosure::first()->closed_at);
    }

    public function test_eod_yang_sudah_ditutup_tidak_dapat_ditutup_ulang_tanpa_dibuka(): void
    {
        $this->sell(1);
        $this->close(company_today());

        $this->actingAs($this->owner)->post(route('admin.eod.store'), [
            'store_id' => $this->store->id,
            'business_date' => company_today(),
            'denominations' => [10000 => 5],
        ])->assertSessionHas('error');

        $this->assertSame(10000.0, (float) StoreClosure::first()->cash_counted);
    }

    public function test_review_stok_dapat_diselesaikan(): void
    {
        // Offline dengan stok kurang → flag review.
        $this->actingAs($this->cashier)->postJson('/pos/sales', [
            'client_uuid' => (string) Str::uuid(),
            'origin' => 'offline',
            'lines' => [['product_id' => $this->product->id, 'qty' => 500]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 5000000, 'status' => 'confirmed']],
        ])->assertStatus(201);

        $sale = Sale::latest('created_at')->first();
        $this->assertSame('pending', $sale->stock_review_status);

        $this->actingAs($this->owner)
            ->post(route('admin.stock-review.resolve', $sale), ['action' => 'valid'])
            ->assertRedirect();

        $this->assertSame('resolved', $sale->refresh()->stock_review_status);
    }
}
