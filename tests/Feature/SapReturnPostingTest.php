<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\SaleService;
use App\Support\Sap\SapReturnPosting;
use App\Support\Sap\SapSalePosting;
use App\Support\Sap\SapStockMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\TestCase;

class SapReturnPostingTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private Warehouse $warehouse;

    private User $owner;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co', 'ppn_enabled' => true, 'ppn_rate' => 11, 'ppn_inclusive' => false,
            'allow_negative_stock' => true, 'sap_enabled' => true, 'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1', 'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager', 'sap_password' => 'secret',
        ]);
        $taxGroup = TaxGroup::factory()->create(['company_id' => $this->company->id, 'code' => 'PPNK-11', 'rate' => 11]);
        $this->company->update(['tax_group_id' => $taxGroup->id]);

        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id, 'code' => 'G.001.MN', 'cash_account' => '1100-1001-0002',
        ]);
        $customer = Customer::create([
            'company_id' => $this->company->id, 'code' => 'CR00001', 'name' => 'PELANGGAN UMUM',
            'is_member' => false, 'is_active' => true, 'source' => Customer::SOURCE_SAP,
        ]);
        $this->store = $this->makeStore('STR001', $this->warehouse, $customer);
        $this->owner = User::create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Owner',
            'username' => 'owner1', 'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password',
        ]);
        $this->cashier = User::create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Kasir',
            'username' => 'kasir1', 'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password',
        ]);

        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum']);
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->product = Product::create([
            'company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id,
            'sku' => 'INDF-0001', 'name' => 'Indomie', 'base_price' => 4000, 'track_stock' => true, 'is_active' => true,
        ]);
    }

    private function makeStore(string $code, Warehouse $warehouse, Customer $customer): Store
    {
        return Store::create([
            'company_id' => $this->company->id, 'code' => $code, 'name' => $code, 'pic_name' => 'PIC',
            'currency' => 'IDR', 'warehouse_id' => $warehouse->id, 'default_customer_id' => $customer->id,
        ]);
    }

    /**
     * @param  array{memoTotal?: float, memo?: ?array<string, mixed>, refund?: ?array<string, mixed>, invoiceFails?: bool}  $options
     */
    private function fakeSap(array $options = []): void
    {
        Http::swap(new HttpFactory);

        Http::fake(function (Request $request) use ($options) {
            $url = $request->url();
            $get = $request->method() === 'GET';

            return match (true) {
                str_ends_with($url, '/Login') => Http::response(['SessionId' => 'abc']),
                str_ends_with($url, '/Logout') => Http::response('', 204),
                $get && str_contains($url, '/Invoices(') => Http::response(['DocTotal' => 8880, 'DocumentLines' => [['LineNum' => 3, 'ItemCode' => 'INDF-0001']]]),
                $get && str_contains($url, '/CreditNotes(') => Http::response(['DocTotal' => $options['memoTotal'] ?? 4440]),
                $get && str_contains($url, '/CreditNotes') => Http::response(['value' => array_filter([$options['memo'] ?? null])]),
                $get && str_contains($url, '/VendorPayments') => Http::response(['value' => array_filter([$options['refund'] ?? null])]),
                $get && str_contains($url, '/Invoices') => Http::response(['value' => []]),
                $get && str_contains($url, '/IncomingPayments') => Http::response(['value' => []]),
                ($options['invoiceFails'] ?? false) && str_ends_with($url, '/Invoices') => Http::response(['error' => ['message' => ['value' => 'SAP menolak']]], 400),
                str_ends_with($url, '/Invoices') => Http::response(['DocEntry' => 4, 'DocNum' => 2600001], 201),
                str_ends_with($url, '/IncomingPayments') => Http::response(['DocEntry' => 7, 'DocNum' => 2600003], 201),
                str_ends_with($url, '/CreditNotes') => Http::response(['DocEntry' => 12, 'DocNum' => 2700001], 201),
                str_ends_with($url, '/VendorPayments') => Http::response(['DocEntry' => 15, 'DocNum' => 2800001], 201),
                default => Http::response('', 204),
            };
        });
    }

    private function sell(): Sale
    {
        $cash = PaymentType::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'CASH'],
            ['count_type' => 'summary', 'order_number' => 1, 'is_active' => true],
        );

        return SaleService::create([
            'store_id' => $this->store->id,
            'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            'payments' => [['payment_type_id' => $cash->id, 'amount' => 10000]],
        ], $this->cashier)->fresh();
    }

    private function giveBack(Sale $sale, string $refundMethod = 'cash', float $qty = 1, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->cashier)->postJson('/pos/returns', [
            'sale_id' => $sale->id,
            'refund_method' => $refundMethod,
            'lines' => [['sale_line_id' => $sale->lines()->first()->id, 'qty' => $qty]],
        ]);
    }

    public function test_retur_tunai_diposting_sebagai_credit_memo_dan_outgoing_payment(): void
    {
        $this->fakeSap();
        $sale = $this->sell();

        $response = $this->giveBack($sale)->assertCreated();
        $return = SalesReturn::findOrFail($response->json('data.id'));

        $this->assertSame(SalesReturn::SAP_POSTED, $return->sap_post_status);
        $this->assertSame([14, '2700001', 12], [$return->sap_credit_memo_obj_type, $return->sap_credit_memo_doc_num, $return->sap_credit_memo_doc_entry]);
        $this->assertSame([46, '2800001', 15], [$return->sap_refund_obj_type, $return->sap_refund_doc_num, $return->sap_refund_doc_entry]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/CreditNotes')
            && $request['CardCode'] === 'CR00001'
            && $request['U_SOL_FRST'] === 'Y'
            && $request['U_SOL_TRKY'] === $return->id
            && $request['U_SOL_STID'] === $this->store->id
            && $request['JournalMemo'] === $return->number
            && $request['DocumentLines'] === [['BaseType' => 13, 'BaseEntry' => 4, 'BaseLine' => 3, 'Quantity' => 1.0, 'WarehouseCode' => 'G.001.MN']]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/VendorPayments')
            && $request['DocType'] === 'rCustomer'
            && $request['CashAccount'] === '1100-1001-0002'
            && $request['CashSum'] === 4440.0
            && $request['U_SOL_TRKY'] === $return->id
            && $request['JournalRemarks'] === $return->number
            && $request['PaymentInvoices'] === [['DocEntry' => 12, 'InvoiceType' => 'it_CredItnote', 'SumApplied' => 4440.0]]);
    }

    public function test_retur_mengembalikan_stok_ke_buku_besar_warehouse(): void
    {
        $this->fakeSap();
        $sale = $this->sell();
        $onHand = fn () => (float) WarehouseStock::where('warehouse_id', $this->warehouse->id)->where('product_id', $this->product->id)->value('on_hand');
        $before = $onHand();

        $returnId = $this->giveBack($sale)->assertCreated()->json('data.id');

        $this->assertEqualsWithDelta($before + 1, $onHand(), 0.0001);
        $this->assertDatabaseHas('warehouse_stock_ledgers', [
            'warehouse_id' => $this->warehouse->id, 'ref_type' => 'sales_return', 'ref_id' => $returnId, 'type' => 'sale_return',
        ]);
    }

    public function test_pengembalian_non_tunai_hanya_membuat_credit_memo(): void
    {
        $this->fakeSap();
        $sale = $this->sell();

        $return = SalesReturn::findOrFail($this->giveBack($sale, 'qris')->assertCreated()->json('data.id'));

        $this->assertSame(SalesReturn::SAP_POSTED, $return->sap_post_status);
        $this->assertSame('2700001', $return->sap_credit_memo_doc_num);
        $this->assertNull($return->sap_refund_doc_num);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/VendorPayments'));
    }

    public function test_kirim_ulang_idempoten_memakai_dokumen_sap_yang_sudah_ada(): void
    {
        $this->fakeSap();
        $sale = $this->sell();
        $return = SalesReturn::findOrFail($this->giveBack($sale)->assertCreated()->json('data.id'));
        $return->forceFill([
            'sap_post_status' => SalesReturn::SAP_FAILED, 'sap_credit_memo_doc_entry' => null, 'sap_credit_memo_doc_num' => null,
            'sap_refund_doc_entry' => null, 'sap_refund_doc_num' => null,
        ])->saveQuietly();

        $this->fakeSap(['memo' => ['DocEntry' => 12, 'DocNum' => 2700001], 'refund' => ['DocEntry' => 15, 'DocNum' => 2800001]]);
        SapReturnPosting::queue($return->fresh());

        $this->assertSame(SalesReturn::SAP_POSTED, $return->fresh()->sap_post_status);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && (str_ends_with($request->url(), '/CreditNotes') || str_ends_with($request->url(), '/VendorPayments')));
    }

    public function test_total_credit_memo_berbeda_menahan_pengembalian_dana(): void
    {
        $this->fakeSap(['memoTotal' => 4000]);
        $sale = $this->sell();

        $return = SalesReturn::findOrFail($this->giveBack($sale)->assertCreated()->json('data.id'));

        $this->assertSame(SalesReturn::SAP_FAILED, $return->sap_post_status);
        $this->assertStringContainsString('berbeda dengan total retur', $return->sap_post_error);
        $this->assertSame('2700001', $return->sap_credit_memo_doc_num);
        $this->assertNull($return->sap_refund_doc_num);
    }

    public function test_retur_menunggu_penjualan_terposting_lalu_otomatis_diposting(): void
    {
        $this->fakeSap(['invoiceFails' => true]);
        $sale = $this->sell();
        $this->assertSame(Sale::SAP_FAILED, $sale->sap_post_status);

        $return = SalesReturn::findOrFail($this->giveBack($sale)->assertCreated()->json('data.id'));
        $this->assertSame(SalesReturn::SAP_FAILED, $return->sap_post_status);
        $this->assertStringContainsString('belum memiliki A/R Invoice', $return->sap_post_error);

        $this->fakeSap();
        SapSalePosting::queue($sale->fresh());

        $this->assertSame(Sale::SAP_POSTED, $sale->fresh()->sap_post_status);
        $this->assertSame(SalesReturn::SAP_POSTED, $return->fresh()->sap_post_status);
    }

    public function test_retur_hanya_di_gerai_asal_penjualan(): void
    {
        $this->fakeSap();
        $sale = $this->sell();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $this->company->id, 'code' => 'G.002.MN']);
        $other = $this->makeStore('STR002', $otherWarehouse, Customer::first());
        $otherCashier = User::create([
            'company_id' => $this->company->id, 'store_id' => $other->id, 'name' => 'Kasir 2',
            'username' => 'kasir2', 'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password',
        ]);

        $this->giveBack($sale, 'cash', 1, $otherCashier)->assertStatus(422);

        $this->assertSame(0, SalesReturn::count());
    }

    public function test_retur_tanpa_integrasi_sap_tidak_diposting(): void
    {
        $this->company->update(['sap_enabled' => false]);
        $this->fakeSap();
        $sale = $this->sell();

        $return = SalesReturn::findOrFail($this->giveBack($sale)->assertCreated()->json('data.id'));

        $this->assertNull($return->sap_post_status);
        Http::assertNothingSent();
    }

    public function test_monitor_retur_menampilkan_dan_owner_dapat_kirim_ulang(): void
    {
        $this->fakeSap(['memoTotal' => 4000]);
        $sale = $this->sell();
        $return = SalesReturn::findOrFail($this->giveBack($sale)->assertCreated()->json('data.id'));

        $this->actingAs($this->owner)->get(route('admin.sap-postings.returns'))
            ->assertOk()
            ->assertSee($return->number)
            ->assertSee('2700001');

        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-postings.returns.retry', $return))->assertSessionHas('success');

        $this->assertSame(SalesReturn::SAP_POSTED, $return->fresh()->sap_post_status);
    }

    public function test_mutasi_credit_memo_sap_ditautkan_ke_buku_besar_retur_bukan_dicatat_dua_kali(): void
    {
        $this->fakeSap();
        $sale = $this->sell();
        $return = SalesReturn::findOrFail($this->giveBack($sale)->assertCreated()->json('data.id'));
        $ledger = WarehouseStockLedger::where('ref_type', 'sales_return')->where('ref_id', $return->id)->firstOrFail();
        $this->assertNull($ledger->sap_trans_seq);
        $rowsBefore = WarehouseStockLedger::where('warehouse_id', $this->warehouse->id)->count();

        $row = [
            'TransSeq' => 501, 'TransType' => 14, 'CreatedBy' => 12, 'BASE_REF' => 2700001, 'DocLineNum' => 0, 'DocDate' => '20261109',
            'LocCode' => 'G.001.MN', 'ItemCode' => 'INDF-0001', 'InQty' => 1, 'OutQty' => 0,
        ];
        $service = new SapStockMutationService($this->company);
        $method = new ReflectionMethod($service, 'linkToEasyPosSale');

        $this->assertTrue($method->invoke($service, $row));
        $this->assertSame(501, $ledger->fresh()->sap_trans_seq);
        $this->assertSame($rowsBefore, WarehouseStockLedger::where('warehouse_id', $this->warehouse->id)->count());
    }
}
