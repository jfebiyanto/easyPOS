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
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
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

    public function test_tanggal_bisnis_berikutnya_terkunci_sampai_eod(): void
    {
        $sale = $this->sell(1);
        $yesterday = now()->subDay()->toDateString();
        $sale->update(['business_date' => $yesterday]);

        // Transaksi hari ini diblokir karena EOD kemarin belum selesai.
        $this->actingAs($this->cashier)->postJson('/pos/sales', [
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'origin' => 'online',
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 10000, 'status' => 'confirmed']],
        ])->assertStatus(422);

        // Setelah EOD kemarin, transaksi hari ini diizinkan.
        $this->actingAs($this->owner)->post(route('admin.eod.store'), [
            'store_id' => $this->store->id,
            'business_date' => $yesterday,
            'denominations' => [10000 => 1],
        ])->assertRedirect();

        $this->actingAs($this->cashier)->postJson('/pos/sales', [
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'origin' => 'online',
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 10000, 'status' => 'confirmed']],
        ])->assertStatus(201);
    }

    public function test_review_stok_dapat_diselesaikan(): void
    {
        // Offline dengan stok kurang → flag review.
        $this->actingAs($this->cashier)->postJson('/pos/sales', [
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
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
