<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\MemberLevel;
use App\Models\PaymentType;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Support\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    private User $cashier;

    private Product $product;

    private PaymentType $cash;

    private Customer $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'ppn_enabled' => false, 'ppn_rate' => 0, 'timezone' => 'Asia/Jakarta']);
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

        $silver = MemberLevel::create(['company_id' => $this->company->id, 'name' => 'Silver', 'min_points' => 0, 'earn_amount_per_point' => 10000, 'order' => 0, 'is_active' => true]);
        $this->member = Customer::create(['company_id' => $this->company->id, 'code' => 'CUST-1', 'name' => 'Member Satu', 'phone' => '0812', 'is_member' => true, 'member_level_id' => $silver->id, 'points_balance' => 0, 'is_active' => true]);

        StockService::record($this->store->id, $this->product->id, StockLedger::TYPE_OPENING, 100);
    }

    private function sell(float $qty, ?string $customerId): Sale
    {
        $this->actingAs($this->cashier)->postJson('/pos/sales', [
            'client_uuid' => (string) Str::uuid(),
            'origin' => 'online',
            'customer_id' => $customerId,
            'lines' => [['product_id' => $this->product->id, 'qty' => $qty]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 10000 * $qty, 'status' => 'confirmed']],
        ])->assertStatus(201);

        return Sale::latest('created_at')->first();
    }

    public function test_laporan_penjualan_per_gerai(): void
    {
        $this->sell(2, $this->member->id);

        $this->actingAs($this->owner)
            ->get(route('admin.reports.show', ['report' => 'sales_by_store']))
            ->assertOk()
            ->assertSee('STR001');
    }

    public function test_laporan_penjualan_per_gerai_menampilkan_diskon_promosi(): void
    {
        Promotion::create([
            'company_id' => $this->company->id,
            'code' => 'B2G1',
            'name' => 'Beli 2 Gratis 1',
            'type' => 'buy_x_get_y',
            'product_id' => $this->product->id,
            'buy_qty' => 2,
            'get_qty' => 1,
            'is_active' => true,
        ]);

        $this->sell(3, null);

        $this->actingAs($this->owner)
            ->get(route('admin.reports.show', ['report' => 'sales_by_store']))
            ->assertOk()
            ->assertSeeInOrder(['Diskon', 'Subtotal', 'STR001', '1', 'Rp 10.000', 'Rp 20.000']);
    }

    public function test_produk_terlaris_menampilkan_sku(): void
    {
        $this->sell(3, null);

        $this->actingAs($this->owner)
            ->get(route('admin.reports.show', ['report' => 'best_sellers']))
            ->assertOk()
            ->assertSee('SKU-1');
    }

    public function test_rata_rata_belanja_memisahkan_anonim(): void
    {
        $this->sell(2, $this->member->id);
        $this->sell(1, null);

        $this->actingAs($this->owner)
            ->get(route('admin.reports.show', ['report' => 'customer_spend']))
            ->assertOk()
            ->assertSee('Member Satu')
            ->assertSee('Anonim');
    }

    public function test_ekspor_excel(): void
    {
        $this->sell(1, null);

        $res = $this->actingAs($this->owner)
            ->get(route('admin.reports.export', ['report' => 'sales_by_store', 'format' => 'xlsx']))
            ->assertOk();

        $this->assertStringContainsString('.xlsx', $res->headers->get('content-disposition'));
    }

    public function test_ekspor_pdf(): void
    {
        $this->sell(1, null);

        $res = $this->actingAs($this->owner)
            ->get(route('admin.reports.export', ['report' => 'sales_by_sku', 'format' => 'pdf']))
            ->assertOk();

        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }
}
