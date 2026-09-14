<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\PaymentType;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Support\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'origin' => 'online',
            'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 8880, 'status' => 'confirmed']],
        ], $overrides);
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
}
