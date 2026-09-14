<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Delivery;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    private Delivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);
        $this->store = Store::create(['company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $this->owner = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Owner', 'username' => 'owner1', 'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password']);

        $sale = Sale::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'business_date' => company_today(),
            'type' => 'sale',
            'status' => 'completed',
            'total' => 20000,
            'currency_code' => 'IDR',
            'sold_at' => now(),
        ]);

        $this->delivery = Delivery::create([
            'sale_id' => $sale->id,
            'recipient_name' => 'Budi',
            'phone' => '0812',
            'address' => 'Jl. Merdeka 1',
            'shipping_cost' => 5000,
            'status' => 'pending',
        ]);
    }

    public function test_daftar_pengiriman_dapat_diakses(): void
    {
        $this->actingAs($this->owner)->get(route('admin.deliveries.index'))->assertOk();
    }

    public function test_status_pengiriman_dapat_diubah(): void
    {
        $this->actingAs($this->owner)
            ->put(route('admin.deliveries.update', $this->delivery), ['status' => 'shipped'])
            ->assertRedirect();

        $this->delivery->refresh();
        $this->assertSame('shipped', $this->delivery->status);
        $this->assertNotNull($this->delivery->shipped_at);

        $this->put(route('admin.deliveries.update', $this->delivery), ['status' => 'delivered']);
        $this->assertNotNull($this->delivery->refresh()->delivered_at);
    }
}
