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
use App\Models\SalesReturn;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Support\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        $report = $this->storeReport();

        $this->assertSame(
            ['Gerai', 'Transaksi', 'Penjualan Kotor', 'Diskon', 'Penjualan Neto', 'PPN', 'Ongkir', 'Omzet', 'Retur', 'Net'],
            $report['columns'],
        );
        $this->assertEqualsWithDelta([1, 30000, 10000, 20000, 0, 0, 20000, 0, 20000], array_slice($report['rows'][0], 1), 0.001);
    }

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    private function storeReport(): array
    {
        return $this->actingAs($this->owner)
            ->get(route('admin.reports.show', ['report' => 'sales_by_store', 'from' => company_today(), 'to' => company_today()]))
            ->assertOk()
            ->viewData('data');
    }

    private function sellWithShipping(float $qty, float $shipping): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/sales', [
            'client_uuid' => (string) Str::uuid(),
            'origin' => 'online',
            'shipping_cost' => $shipping,
            'lines' => [['product_id' => $this->product->id, 'qty' => $qty]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 100000, 'status' => 'confirmed']],
        ])->assertStatus(201);
    }

    public function test_penjualan_per_gerai_ppn_eksklusif_menjumlah_neto_ppn_dan_ongkir_menjadi_omzet(): void
    {
        $this->company->update(['ppn_enabled' => true, 'ppn_rate' => 11, 'ppn_inclusive' => false]);

        $this->sellWithShipping(2, 5000);

        // Kotor 20.000, neto 20.000, PPN 2.200, ongkir 5.000 → omzet 27.200.
        $this->assertEqualsWithDelta([1, 20000, 0, 20000, 2200, 5000, 27200, 0, 27200], array_slice($this->storeReport()['rows'][0], 1), 0.001);
    }

    public function test_penjualan_per_gerai_ppn_inklusif_memisahkan_ppn_dari_neto(): void
    {
        $this->company->update(['ppn_enabled' => true, 'ppn_rate' => 11, 'ppn_inclusive' => true]);

        $this->sell(2, null);

        // Harga 20.000 sudah termasuk PPN 11%: neto 18.018,02 + PPN 1.981,98 = omzet 20.000.
        $row = array_slice($this->storeReport()['rows'][0], 1);
        $this->assertEqualsWithDelta([1, 18018.02, 0, 18018.02, 1981.98, 0, 20000, 0, 20000], $row, 0.01);
        $this->assertEqualsWithDelta($row[6], $row[3] + $row[4] + $row[5], 0.01);
    }

    public function test_retur_dihitung_berdasarkan_tanggal_menurut_zona_waktu_perusahaan(): void
    {
        $saleId = $this->sell(3, null)->id;
        $lineId = Sale::with('lines')->find($saleId)->lines->first()->id;

        $makeReturn = fn () => $this->actingAs($this->cashier)->postJson('/pos/returns', [
            'sale_id' => $saleId, 'refund_method' => 'cash', 'lines' => [['sale_line_id' => $lineId, 'qty' => 1]],
        ])->assertStatus(201)->json('data.id');

        $startOfDay = Carbon::parse(company_today(), company_timezone());
        // 01:00 WIB hari ini (= kemarin 18:00 UTC): termasuk hari ini. 01:00 WIB besok: bukan hari ini.
        SalesReturn::whereKey($makeReturn())->update(['created_at' => $startOfDay->copy()->addHour()->utc()]);
        SalesReturn::whereKey($makeReturn())->update(['created_at' => $startOfDay->copy()->addDay()->addHour()->utc()]);

        $row = $this->storeReport()['rows'][0];

        $this->assertEquals(10000, $row[8]);
        $this->assertEquals(20000, $row[9]);
    }

    public function test_produk_terlaris_menampilkan_sku(): void
    {
        $this->sell(3, null);

        $this->actingAs($this->owner)
            ->get(route('admin.reports.show', ['report' => 'best_sellers']))
            ->assertOk()
            ->assertSee('SKU-1');
    }

    public function test_produk_terlaris_diurutkan_dari_qty_terbanyak_dan_dibatasi_20(): void
    {
        $fillerSkus = [];
        $lines = [];
        foreach (range(1, 20) as $n) {
            $product = $this->makeUntrackedProduct(sprintf('A-%02d', $n));
            $fillerSkus[] = $product->sku;
            $lines[] = ['product_id' => $product->id, 'qty' => 1];
        }
        $bestSeller = $this->makeUntrackedProduct('Z-PALING-LAKU');
        $lines[] = ['product_id' => $bestSeller->id, 'qty' => 5];

        $this->actingAs($this->cashier)->postJson('/pos/sales', [
            'client_uuid' => (string) Str::uuid(),
            'origin' => 'online',
            'lines' => $lines,
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 250000, 'status' => 'confirmed']],
        ])->assertStatus(201);

        $rows = $this->actingAs($this->owner)
            ->get(route('admin.reports.show', ['report' => 'best_sellers']))
            ->assertOk()
            ->viewData('data')['rows'];

        $this->assertCount(20, $rows);
        $this->assertSame('Z-PALING-LAKU', $rows[0][0]);
        $this->assertEquals(5, $rows[0][2]);
        $this->assertSame([5, 1], [(int) $rows[0][2], (int) $rows[1][2]]);
    }

    private function makeUntrackedProduct(string $sku): Product
    {
        return Product::create([
            'company_id' => $this->company->id,
            'product_group_id' => $this->product->product_group_id,
            'unit_id' => $this->product->unit_id,
            'sku' => $sku,
            'name' => "Produk {$sku}",
            'base_price' => 10000,
            'track_stock' => false,
            'is_active' => true,
        ]);
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
