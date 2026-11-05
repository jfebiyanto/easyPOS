<?php

namespace Tests\Feature;

use App\Jobs\PostSaleToSap;
use App\Models\CompanyDetail;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);
        $this->store = Store::create(['company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);

        $this->sale = Sale::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'business_date' => company_today(),
            'type' => 'sale',
            'status' => 'completed',
            'total' => 50000,
            'paid_total' => 50000,
            'currency_code' => 'IDR',
            'sold_at' => now(),
        ]);
    }

    public function test_webhook_menolak_bila_provider_belum_dikonfigurasi(): void
    {
        $this->postJson('/api/webhooks/payments/xendit', ['external_id' => 'X1', 'status' => 'PAID'])
            ->assertStatus(503);
    }

    public function test_webhook_menolak_token_salah(): void
    {
        $this->company->update(['payment_providers' => ['xendit' => ['secret_key' => 'sk_test', 'callback_token' => 'secret-token']]]);

        $this->postJson('/api/webhooks/payments/xendit', ['external_id' => 'X1', 'status' => 'PAID'], ['x-callback-token' => 'wrong'])
            ->assertStatus(403);
    }

    public function test_webhook_mengonfirmasi_pembayaran(): void
    {
        $this->company->update(['payment_providers' => ['xendit' => ['secret_key' => 'sk_test', 'callback_token' => 'secret-token']]]);

        $payment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'amount' => 50000,
            'status' => 'pending',
            'provider' => 'xendit',
            'provider_ref' => 'X1',
        ]);

        $this->postJson('/api/webhooks/payments/xendit', ['external_id' => 'X1', 'status' => 'PAID'], ['x-callback-token' => 'secret-token'])
            ->assertOk();

        $this->assertSame('confirmed', $payment->refresh()->status);
        $this->assertNotNull($payment->confirmed_at);
    }

    public function test_konfirmasi_pembayaran_mengantrekan_ulang_posting_sap_penjualan(): void
    {
        Queue::fake();
        $this->company->update([
            'payment_providers' => ['xendit' => ['secret_key' => 'sk_test', 'callback_token' => 'secret-token']],
            'sap_enabled' => true,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
            'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager',
            'sap_password' => 'secret',
        ]);
        $this->sale->update(['sap_post_status' => Sale::SAP_FAILED]);
        SalePayment::create(['sale_id' => $this->sale->id, 'amount' => 50000, 'status' => 'pending', 'provider' => 'xendit', 'provider_ref' => 'X1']);

        $this->postJson('/api/webhooks/payments/xendit', ['external_id' => 'X1', 'status' => 'PAID'], ['x-callback-token' => 'secret-token'])
            ->assertOk();

        Queue::assertPushed(PostSaleToSap::class, fn (PostSaleToSap $job) => $job->saleId === $this->sale->id);
    }
}
