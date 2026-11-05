<?php

namespace Tests\Feature;

use App\Jobs\PostSaleToSap;
use App\Models\CompanyDetail;
use App\Models\CreditCard;
use App\Models\Customer;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\Store;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\SaleService;
use App\Support\Sap\SapSalePosting;
use App\Support\Sap\SapStockMutationService;
use App\Support\StockService;
use App\Support\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SapSalePostingTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    private User $cashier;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co',
            'ppn_enabled' => true,
            'ppn_rate' => 11,
            'ppn_inclusive' => false,
            'allow_negative_stock' => true,
            'sap_enabled' => true,
            'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
            'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager',
            'sap_password' => 'secret',
        ]);
        $taxGroup = TaxGroup::factory()->create(['company_id' => $this->company->id, 'code' => 'PPNK-11', 'rate' => 11]);
        $this->company->update(['tax_group_id' => $taxGroup->id]);

        $warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id, 'code' => 'G.001.MN', 'cash_account' => '1100-1001-0002',
            'qris_account' => '1100-2002-0004', 'edc_account' => '1100-2002-0003',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->company->id, 'code' => 'CR00001', 'name' => 'PELANGGAN UMUM',
            'is_member' => false, 'is_active' => true, 'source' => Customer::SOURCE_SAP,
        ]);
        $this->store = Store::create([
            'company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai', 'pic_name' => 'PIC',
            'currency' => 'IDR', 'warehouse_id' => $warehouse->id, 'default_customer_id' => $this->customer->id,
        ]);
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

    /**
     * @param  array{invoice?: ?array<string, mixed>, payment?: ?array<string, mixed>, docTotal?: float}  $options  dokumen yang sudah ada di SAP (pencarian U_SOL_TRKY)
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
                $get && str_contains($url, '/Invoices(') => Http::response(['DocTotal' => $options['docTotal'] ?? 8880]),
                $get && str_contains($url, '/Invoices') => Http::response(['value' => array_filter([$options['invoice'] ?? null])]),
                $get && str_contains($url, '/IncomingPayments') => Http::response(['value' => array_filter([$options['payment'] ?? null])]),
                str_ends_with($url, '/Invoices') => Http::response(['DocEntry' => 4, 'DocNum' => 2600001], 201),
                str_ends_with($url, '/IncomingPayments') => Http::response(['DocEntry' => 7, 'DocNum' => 2600003], 201),
                default => Http::response('', 204),
            };
        });
    }

    /**
     * Kartu kredit SAP demo yang sudah disinkronkan: QRIS (1) dan EDC BANK (2).
     */
    private function creditCard(string $code): CreditCard
    {
        return CreditCard::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => $code],
            ['name' => $code === '1' ? 'QRIS' : 'EDC BANK', 'gl_account' => $code === '1' ? '1100-2002-0004' : '1100-2002-0003'],
        );
    }

    /**
     * Tipe pembayaran uji; QRIS dan EDC dipetakan ke kartu kredit SAP, CASH dan lainnya tidak.
     */
    private function paymentType(string $code): PaymentType
    {
        $card = ['QRIS' => '1', 'EDC' => '2'][$code] ?? null;

        return PaymentType::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => $code],
            ['count_type' => 'summary', 'order_number' => 1, 'is_active' => true, 'credit_card_id' => $card ? $this->creditCard($card)->id : null],
        );
    }

    /**
     * @param  list<array{0: string, 1: float, 2?: string}>  $payments  [kode tipe pembayaran, nominal, status]
     */
    private function sellWith(array $payments): Sale
    {
        return SaleService::create([
            'store_id' => $this->store->id,
            'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            'payments' => array_map(fn (array $p) => [
                'payment_type_id' => $this->paymentType($p[0])->id,
                'amount' => $p[1],
                'status' => $p[2] ?? 'confirmed',
            ], $payments),
        ], $this->cashier)->fresh();
    }

    private function sell(): Sale
    {
        return SaleService::create([
            'store_id' => $this->store->id,
            'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            'payments' => [['payment_type_id' => $this->paymentType('CASH')->id, 'amount' => 10000]],
        ], $this->cashier)->fresh();
    }

    public function test_penjualan_diposting_sebagai_invoice_dan_incoming_payment_terekonsiliasi(): void
    {
        $this->fakeSap();

        $sale = $this->sell();

        $this->assertSame(Sale::SAP_POSTED, $sale->sap_post_status);
        $this->assertSame([13, '2600001', 4], [$sale->sap_invoice_obj_type, $sale->sap_invoice_doc_num, $sale->sap_invoice_doc_entry]);
        $this->assertSame([24, '2600003', 7], [$sale->sap_payment_obj_type, $sale->sap_payment_doc_num, $sale->sap_payment_doc_entry]);
        $this->assertNotNull($sale->sap_posted_at);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Invoices')
            && $request['CardCode'] === 'CR00001'
            && $request['U_SOL_FRST'] === 'Y'
            && $request['U_SOL_TRKY'] === $sale->id
            && $request['U_SOL_STID'] === $this->store->id
            && $request['DocumentLines'] === [['ItemCode' => 'INDF-0001', 'Quantity' => 2.0, 'Price' => 4000.0, 'WarehouseCode' => 'G.001.MN', 'VatGroup' => 'PPNK-11']]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/IncomingPayments')
            && $request['CashAccount'] === '1100-1001-0002'
            && $request['CashSum'] === 8880.0
            && $request['U_SOL_TRKY'] === $sale->id
            && $request['PaymentInvoices'] === [['DocEntry' => 4, 'InvoiceType' => 'it_Invoice', 'SumApplied' => 8880.0]]);
    }

    public function test_posting_date_sap_memakai_tanggal_bisnis_eod_tertunda_bukan_hari_ini(): void
    {
        $this->fakeSap();
        $yesterday = now('Asia/Jakarta')->subDay()->toDateString();
        $this->sell()->update(['business_date' => $yesterday]);

        $sale = $this->sell();

        $this->assertSame($yesterday, $sale->business_date->toDateString());
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Invoices')
            && $request['U_SOL_TRKY'] === $sale->id
            && $request['DocDate'] === $yesterday
            && $request['TaxDate'] === $yesterday);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/IncomingPayments')
            && $request['U_SOL_TRKY'] === $sale->id
            && $request['DocDate'] === $yesterday);
    }

    public function test_pembayaran_qris_dicatat_sebagai_credit_card_dengan_akun_qris_warehouse(): void
    {
        $this->fakeSap();

        $sale = $this->sellWith([['QRIS', 8880]]);

        $this->assertSame(Sale::SAP_POSTED, $sale->sap_post_status);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/IncomingPayments')
            && ! isset($request['CashAccount']) && ! isset($request['CashSum'])
            && $request['PaymentCreditCards'] === [[
                'CreditCard' => 1,
                'CreditCardNumber' => '1234',
                'CardValidUntil' => $sale->business_date->copy()->addYear()->endOfMonth()->toDateString(),
                'VoucherNum' => $sale->number,
                'CreditSum' => 8880.0,
                'CreditCur' => 'IDR',
                'CreditAcct' => '1100-2002-0004',
            ]]
            && $request['PaymentInvoices'] === [['DocEntry' => 4, 'InvoiceType' => 'it_Invoice', 'SumApplied' => 8880.0]]);
    }

    public function test_pembayaran_edc_memakai_kartu_dan_akun_edc_warehouse(): void
    {
        $this->fakeSap();

        $this->sellWith([['EDC', 8880]]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/IncomingPayments')
            && $request['PaymentCreditCards'][0]['CreditCard'] === 2
            && $request['PaymentCreditCards'][0]['CreditAcct'] === '1100-2002-0003'
            && $request['PaymentCreditCards'][0]['CreditCardNumber'] === '1234');
    }

    public function test_pembayaran_campuran_tunai_dan_qris_tidak_menghitung_kembalian_tunai(): void
    {
        $this->fakeSap();

        $sale = $this->sellWith([['QRIS', 5000], ['CASH', 5000]]);

        $this->assertSame(Sale::SAP_POSTED, $sale->sap_post_status);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/IncomingPayments')
            && $request['CashAccount'] === '1100-1001-0002'
            && $request['CashSum'] === 3880.0
            && $request['PaymentCreditCards'][0]['CreditSum'] === 5000.0);
    }

    public function test_akun_qris_warehouse_kosong_menahan_posting_sebelum_invoice_dibuat(): void
    {
        $this->fakeSap();
        $this->store->warehouse->update(['qris_account' => null]);

        $sale = $this->sellWith([['QRIS', 8880]]);

        $this->assertSame(Sale::SAP_FAILED, $sale->sap_post_status);
        $this->assertStringContainsString('U_SOL_QRIS_ACC', $sale->sap_post_error);
        $this->assertNull($sale->sap_invoice_doc_entry);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Invoices'));
    }

    public function test_akun_edc_warehouse_kosong_menahan_posting(): void
    {
        $this->fakeSap();
        $this->store->warehouse->update(['edc_account' => null]);

        $sale = $this->sellWith([['EDC', 8880]]);

        $this->assertSame(Sale::SAP_FAILED, $sale->sap_post_status);
        $this->assertStringContainsString('U_SOL_EDC_ACC', $sale->sap_post_error);
    }

    public function test_pembayaran_qris_pending_menahan_posting_sampai_dikonfirmasi(): void
    {
        $this->fakeSap();

        $sale = $this->sellWith([['QRIS', 8880, 'pending']]);

        $this->assertSame(Sale::SAP_FAILED, $sale->sap_post_status);
        $this->assertStringContainsString('belum terkonfirmasi', $sale->sap_post_error);
        $this->assertNull($sale->sap_invoice_doc_entry);

        $sale->payments()->update(['status' => 'confirmed']);
        SapSalePosting::queue($sale->fresh());

        $this->assertSame(Sale::SAP_POSTED, $sale->fresh()->sap_post_status);
    }

    public function test_tipe_pembayaran_tanpa_pemetaan_credit_card_menahan_posting(): void
    {
        $this->fakeSap();

        $sale = $this->sellWith([['CARD', 8880]]);

        $this->assertSame(Sale::SAP_FAILED, $sale->sap_post_status);
        $this->assertStringContainsString('Tipe pembayaran CARD belum dipetakan ke Credit Card SAP B1', $sale->sap_post_error);
        $this->assertNull($sale->sap_invoice_doc_entry);
    }

    public function test_tipe_pembayaran_lain_yang_dipetakan_memakai_kartu_dan_akun_gl_kartu_sap(): void
    {
        $this->fakeSap();
        PaymentType::create([
            'company_id' => $this->company->id, 'code' => 'CARD', 'count_type' => 'summary', 'order_number' => 3,
            'is_active' => true, 'credit_card_id' => $this->creditCard('2')->id,
        ]);

        $sale = $this->sellWith([['CARD', 8880]]);

        $this->assertSame(Sale::SAP_POSTED, $sale->sap_post_status);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/IncomingPayments')
            && $request['PaymentCreditCards'][0]['CreditCard'] === 2
            && $request['PaymentCreditCards'][0]['CreditAcct'] === '1100-2002-0003'
            && $request['PaymentCreditCards'][0]['CreditSum'] === 8880.0);
    }

    public function test_profit_center_warehouse_dicatat_di_baris_invoice(): void
    {
        $this->fakeSap();
        $this->store->warehouse->update(['profit_center' => 'R001']);

        $this->sell();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Invoices')
            && $request['DocumentLines'][0]['CostingCode'] === 'R001'
            && $request['DocumentLines'][0]['COGSCostingCode'] === 'R001');
    }

    public function test_profit_center_kosong_dibiarkan_kosong_di_baris_invoice(): void
    {
        $this->fakeSap();

        $this->sell();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Invoices')
            && ! array_key_exists('CostingCode', $request['DocumentLines'][0])
            && ! array_key_exists('COGSCostingCode', $request['DocumentLines'][0]));
    }

    public function test_ppn_inklusif_mengirim_harga_di_price_after_vat(): void
    {
        $this->fakeSap();
        $this->company->update(['ppn_inclusive' => true]);

        $sale = $this->sell();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Invoices')
            && $request['DocumentLines'][0]['PriceAfterVAT'] === round((float) $sale->lines[0]->line_total / 2, 6)
            && ! array_key_exists('Price', $request['DocumentLines'][0])
            && ! array_key_exists('GrossPrice', $request['DocumentLines'][0])
            && ! array_key_exists('LineTotal', $request['DocumentLines'][0]));
    }

    public function test_dokumen_yang_sudah_ada_di_sap_tidak_dibuat_dua_kali(): void
    {
        $this->fakeSap(['invoice' => ['DocEntry' => 4, 'DocNum' => 2600001], 'payment' => ['DocEntry' => 7, 'DocNum' => 2600003]]);

        $sale = $this->sell();

        $this->assertSame(Sale::SAP_POSTED, $sale->sap_post_status);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && (str_ends_with($request->url(), '/Invoices') || str_ends_with($request->url(), '/IncomingPayments')));
    }

    public function test_total_sap_berbeda_menahan_pembayaran_dan_kirim_ulang_tidak_membuat_invoice_lagi(): void
    {
        $this->fakeSap(['docTotal' => 8881]);
        $sale = $this->sell();

        $this->assertSame(Sale::SAP_FAILED, $sale->sap_post_status);
        $this->assertSame(4, $sale->sap_invoice_doc_entry);
        $this->assertNull($sale->sap_payment_doc_entry);
        $this->assertStringContainsString('berbeda', $sale->sap_post_error);

        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.sap-postings.retry', $sale))->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame(Sale::SAP_POSTED, $sale->sap_post_status);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Invoices'));
    }

    public function test_customer_tanpa_cardcode_menunggu_lalu_diposting_setelah_customer_tersinkron(): void
    {
        $this->fakeSap();
        $customer = Customer::create([
            'company_id' => $this->company->id, 'name' => 'Budi', 'phone' => '0812', 'is_member' => false,
            'is_active' => true, 'source' => Customer::SOURCE_MANUAL, 'sap_sync_status' => Customer::SYNC_FAILED,
        ]);
        $this->store->update(['default_customer_id' => $customer->id]);

        $sale = $this->sell();

        $this->assertSame(Sale::SAP_FAILED, $sale->sap_post_status);
        $this->assertStringContainsString('CardCode', $sale->sap_post_error);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/Invoices'));

        // Customer berhasil dikirim ke SAP → penjualannya ikut diposting.
        Http::swap(new HttpFactory);
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'SeriesService_GetDocumentSeries') => Http::response(['value' => [['Series' => 70, 'IsManual' => 'tNO', 'Locked' => 'tNO', 'U_SOL_RETAIL' => 'Y']]]),
                $request->method() === 'GET' && str_contains($url, '/BusinessPartners') => Http::response(['value' => []]),
                str_ends_with($url, '/BusinessPartners') => Http::response(['CardCode' => 'CR00002'], 201),
                $request->method() === 'GET' && str_contains($url, '/Invoices(') => Http::response(['DocTotal' => 8880]),
                $request->method() === 'GET' => Http::response(['value' => []]),
                str_ends_with($url, '/Invoices') => Http::response(['DocEntry' => 5, 'DocNum' => 2600002], 201),
                str_ends_with($url, '/IncomingPayments') => Http::response(['DocEntry' => 8, 'DocNum' => 2600004], 201),
                default => Http::response(['SessionId' => 'abc']),
            };
        });
        $this->actingAs($this->owner)->post(route('admin.customers.sap-sync', $customer));

        $this->assertSame(Sale::SAP_POSTED, $sale->fresh()->sap_post_status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Invoices') && $request['CardCode'] === 'CR00002');
    }

    public function test_warehouse_tanpa_akun_kas_gagal_diposting(): void
    {
        $this->fakeSap();
        $this->store->warehouse->update(['cash_account' => null]);

        $sale = $this->sell();

        $this->assertSame(Sale::SAP_FAILED, $sale->sap_post_status);
        $this->assertStringContainsString('U_SOL_CASH_ACC', $sale->sap_post_error);
        Http::assertNothingSent();
    }

    public function test_tanpa_integrasi_sap_penjualan_tidak_diposting(): void
    {
        Queue::fake();
        $this->company->update(['sap_enabled' => false]);

        $sale = $this->sell();

        $this->assertNull($sale->sap_post_status);
        Queue::assertNotPushed(PostSaleToSap::class);
    }

    public function test_penjualan_memakai_stok_warehouse_gerai_dan_mutasi_invoice_sap_ditautkan_tanpa_dobel(): void
    {
        $this->company->update(['allow_negative_stock' => false]);
        $warehouse = $this->store->warehouse;
        WarehouseStockService::record($warehouse, $this->product->id, WarehouseStockLedger::TYPE_PURCHASE, 10, ['attributes' => ['sap_trans_seq' => 1]]);
        $this->fakeSap();

        $sale = $this->sell();

        $this->assertSame('8.000', WarehouseStock::where('warehouse_id', $warehouse->id)->value('on_hand'));
        $this->assertSame(0.0, StockService::balance($this->store->id, $this->product->id));
        $this->assertSame(Sale::SAP_POSTED, $sale->sap_post_status);

        // Mutasi OIVL dari A/R Invoice easyPOS (DocEntry 4) ditautkan ke baris penjualan.
        Http::swap(new HttpFactory);
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/List') => Http::response(['value' => [[
                    'TransSeq' => 2, 'TransType' => 13, 'CreatedBy' => 4, 'BASE_REF' => '2600001', 'DocLineNum' => 0,
                    'ItemCode' => 'INDF-0001', 'LocCode' => 'G.001.MN', 'InQty' => 0, 'OutQty' => 2, 'DocDate' => '20261104',
                ]]]),
                $request->method() === 'GET' && str_contains($url, 'SQLQueries') => Http::response(['SqlText' => SapStockMutationService::QUERY_SQL]),
                default => Http::response(['SessionId' => 'abc']),
            };
        });
        $this->company->forceFill(['sap_initial_imported_at' => now(), 'sap_stock_synced_at' => now()])->save();
        $this->artisan('sap:sync-stock')->assertSuccessful();

        $this->assertSame('8.000', WarehouseStock::where('warehouse_id', $warehouse->id)->value('on_hand'));
        $saleLedger = WarehouseStockLedger::where('ref_type', 'sale')->where('ref_id', $sale->id)->sole();
        $this->assertSame(2, $saleLedger->sap_trans_seq);
        $this->assertSame('A/R Invoice 2600001', $saleLedger->sapDocumentLabel());
        $this->assertSame(2, WarehouseStockLedger::count());
    }

    public function test_stok_warehouse_tidak_cukup_menolak_penjualan_online(): void
    {
        Queue::fake();
        $this->company->update(['allow_negative_stock' => false]);
        WarehouseStockService::record($this->store->warehouse, $this->product->id, WarehouseStockLedger::TYPE_PURCHASE, 1);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Stok tidak mencukupi untuk produk INDF-0001');

        $this->sell();
    }

    public function test_void_ditolak_setelah_diposting_ke_sap_dan_mengembalikan_stok_warehouse_bila_belum(): void
    {
        $this->fakeSap();
        $posted = $this->sell();

        try {
            SaleService::void($posted, $this->owner, 'salah input');
            $this->fail('Void penjualan yang sudah diposting seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('SAP B1', $e->getMessage());
        }

        Queue::fake();
        $pending = $this->sell();
        SaleService::void($pending, $this->owner, 'salah input');

        $pending->refresh();
        $this->assertNull($pending->sap_post_status);
        $this->assertSame('-2.000', WarehouseStock::where('warehouse_id', $this->store->warehouse_id)->value('on_hand'));
    }

    public function test_halaman_posting_menampilkan_dokumen_sap_dan_hanya_owner_yang_bisa_kirim_ulang(): void
    {
        $this->fakeSap(['docTotal' => 1]);
        $sale = $this->sell();

        $this->actingAs($this->owner)
            ->get(route('admin.sap-postings.index'))
            ->assertOk()
            ->assertSee($sale->number)
            ->assertSee('A/R Invoice 2600001')
            ->assertSee('Kirim Ulang');

        $this->actingAs($this->cashier)
            ->post(route('admin.sap-postings.retry', $sale))
            ->assertForbidden();
    }
}
