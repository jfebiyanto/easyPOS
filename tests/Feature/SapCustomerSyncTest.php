<?php

namespace Tests\Feature;

use App\Jobs\PushCustomerToSap;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SapCustomerSyncTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $owner;

    private CustomerGroup $retailGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co',
            'sap_enabled' => true,
            'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
            'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager',
            'sap_password' => 'secret',
        ]);
        $store = Store::create(['company_id' => $this->company->id, 'code' => 'GR01', 'name' => 'Gerai', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $this->owner = User::create([
            'company_id' => $this->company->id,
            'store_id' => $store->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
        $this->retailGroup = CustomerGroup::create(['company_id' => $this->company->id, 'code' => '102', 'name' => 'RETAIL', 'source' => CustomerGroup::SOURCE_SAP]);
    }

    /**
     * @param  ?string  $existingCardCode  BP dengan U_SOL_TRKY yang sama sudah ada di SAP
     */
    private function fakeSap(?string $existingCardCode = null, string $isManual = 'tNO'): void
    {
        Http::swap(new HttpFactory);

        Http::fake(function (Request $request) use ($existingCardCode, $isManual) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/Login') => Http::response(['SessionId' => 'abc']),
                str_ends_with($url, '/Logout') => Http::response('', 204),
                str_contains($url, 'SeriesService_GetDefaultSeries') => Http::response(['Series' => 70, 'IsManual' => $isManual]),
                $request->method() === 'GET' && str_contains($url, '/BusinessPartners') => Http::response(['value' => $existingCardCode ? [['CardCode' => $existingCardCode]] : []]),
                $request->method() === 'POST' && str_ends_with($url, '/BusinessPartners') => Http::response(['CardCode' => 'CR00002'], 201),
                default => Http::response('', 204),
            };
        });
    }

    private function storeCustomer(array $overrides = []): Customer
    {
        $this->actingAs($this->owner)->post(route('admin.customers.store'), $overrides + [
            'name' => 'Budi',
            'phone' => '0812',
            'customer_group_id' => $this->retailGroup->id,
            'is_member' => 1,
        ])->assertRedirect(route('admin.customers.index'));

        return Customer::where('name', $overrides['name'] ?? 'Budi')->firstOrFail();
    }

    public function test_customer_baru_dikirim_ke_sap_dan_kodenya_menjadi_cardcode(): void
    {
        $this->fakeSap();

        $customer = $this->storeCustomer();

        $this->assertSame('CR00002', $customer->code);
        $this->assertSame(Customer::SOURCE_MANUAL, $customer->source);
        $this->assertSame(Customer::SYNC_SYNCED, $customer->sap_sync_status);
        $this->assertNotNull($customer->sap_synced_at);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/BusinessPartners')
            && $request['CardType'] === 'cCustomer'
            && $request['Series'] === 70
            && $request['GroupCode'] === 102
            && $request['CardName'] === 'Budi'
            && $request['U_SOL_FRST'] === 'Y'
            && $request['U_SOL_TRKY'] === $customer->id);
    }

    public function test_customer_yang_sudah_ada_di_sap_tidak_dibuat_dua_kali(): void
    {
        $this->fakeSap(existingCardCode: 'CR00009');

        $customer = $this->storeCustomer();

        $this->assertSame('CR00009', $customer->code);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/BusinessPartners'));
    }

    public function test_series_manual_membuat_pengiriman_gagal_dan_dapat_dikirim_ulang(): void
    {
        $this->fakeSap(isManual: 'tYES');
        $customer = $this->storeCustomer();

        $this->assertNull($customer->code);
        $this->assertSame(Customer::SYNC_FAILED, $customer->sap_sync_status);
        $this->assertStringContainsString('series', $customer->sap_sync_error);

        $this->fakeSap();
        $this->actingAs($this->owner)->post(route('admin.customers.sap-sync', $customer))->assertSessionHas('success');

        $this->assertSame('CR00002', $customer->fresh()->code);
    }

    public function test_perubahan_customer_tersinkron_dikirim_ke_sap(): void
    {
        $this->fakeSap();
        $customer = $this->storeCustomer();

        $this->fakeSap();
        $this->actingAs($this->owner)->put(route('admin.customers.update', $customer), [
            'name' => 'Budi Santoso',
            'phone' => '0812',
            'code' => 'UBAH-KODE',
            'customer_group_id' => $this->retailGroup->id,
            'is_member' => 1,
            'is_active' => 1,
        ])->assertRedirect();

        $customer->refresh();
        $this->assertSame('Budi Santoso', $customer->name);
        $this->assertSame('CR00002', $customer->code);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), "/BusinessPartners('CR00002')")
            && $request['CardName'] === 'Budi Santoso');
    }

    public function test_hanya_customer_group_dari_sap_yang_dapat_dipilih(): void
    {
        Queue::fake();
        $manualGroup = CustomerGroup::create(['company_id' => $this->company->id, 'code' => 'VIP', 'name' => 'VIP']);

        $this->actingAs($this->owner)
            ->post(route('admin.customers.store'), ['name' => 'Ani', 'phone' => '0813', 'customer_group_id' => $manualGroup->id])
            ->assertSessionHasErrors('customer_group_id');

        $this->actingAs($this->owner)
            ->get(route('admin.customers.create'))
            ->assertOk()
            ->assertSee('102 · RETAIL')
            ->assertDontSee('VIP · VIP');
    }

    public function test_customer_dari_sap_hanya_status_member_yang_dapat_diubah(): void
    {
        Queue::fake();
        $customer = Customer::create([
            'company_id' => $this->company->id, 'code' => 'CR00001', 'name' => 'PELANGGAN UMUM',
            'phone' => '0800', 'is_member' => true, 'is_active' => true, 'source' => Customer::SOURCE_SAP,
        ]);

        $this->actingAs($this->owner)->put(route('admin.customers.update', $customer), [
            'name' => 'Diubah',
            'phone' => '0899',
            'is_member' => 0,
        ])->assertRedirect();

        $customer->refresh();
        $this->assertSame('PELANGGAN UMUM', $customer->name);
        $this->assertSame('0800', $customer->phone);
        $this->assertTrue($customer->is_active);
        $this->assertFalse($customer->is_member);
        Queue::assertNotPushed(PushCustomerToSap::class);
    }

    public function test_customer_dari_pos_juga_dikirim_ke_sap(): void
    {
        Queue::fake();
        $cashier = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->owner->store_id,
            'name' => 'Kasir',
            'username' => 'kasir1',
            'role' => User::ROLE_CASHIER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->actingAs($cashier)
            ->postJson(route('pos.customers.store'), ['name' => 'Citra', 'phone' => '0814'])
            ->assertCreated()
            ->assertJsonPath('data.code', null);

        $customer = Customer::where('name', 'Citra')->firstOrFail();
        $this->assertSame(Customer::SYNC_PENDING, $customer->sap_sync_status);
        Queue::assertPushed(PushCustomerToSap::class, fn (PushCustomerToSap $job) => $job->customerId === $customer->id);
    }

    public function test_tanpa_integrasi_sap_kode_customer_tetap_dibuat_easypos(): void
    {
        Queue::fake();
        $this->company->update(['sap_enabled' => false]);

        $customer = $this->storeCustomer();

        $this->assertSame('CUST-00001', $customer->code);
        $this->assertNull($customer->sap_sync_status);
        Queue::assertNotPushed(PushCustomerToSap::class);
    }
}
