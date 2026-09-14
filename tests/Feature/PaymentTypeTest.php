<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\PaymentType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTypeTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $admin;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);

        $this->storeA = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'address' => 'Jl. Testing No. 1',
            'pic_name' => 'PIC Satu',
            'pic_phone' => '081200000001',
            'ppn_enabled' => false,
            'ppn_rate' => 11,
            'currency' => 'IDR',
        ]);

        $this->storeB = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR02',
            'name' => 'Gerai Dua',
            'address' => 'Jl. Testing No. 2',
            'pic_name' => 'PIC Dua',
            'pic_phone' => '081200000002',
            'ppn_enabled' => false,
            'ppn_rate' => 11,
            'currency' => 'IDR',
        ]);

        $this->admin = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->storeA->id,
            'name' => 'Admin Test',
            'username' => 'admintest',
            'email' => 'admin@test.local',
            'phone' => '081200000000',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'CASH',
            'description' => 'Pembayaran tunai',
            'order_number' => 1,
            'count_type' => 'summary',
            'store_ids' => [$this->storeA->id, $this->storeB->id],
            'is_active' => '1',
        ], $overrides);
    }

    public function test_halaman_index_dan_form_dapat_diakses(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.payment-types.index'))->assertOk();
        $this->get(route('admin.payment-types.create'))->assertOk();
    }

    public function test_admin_dapat_menambahkan_tipe_pembayaran(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload())
            ->assertRedirect(route('admin.payment-types.index'));

        $paymentType = PaymentType::where('code', 'CASH')->first();
        $this->assertNotNull($paymentType);
        $this->assertSame('Pembayaran tunai', $paymentType->description);
        $this->assertSame(1, $paymentType->order_number);
        $this->assertSame('summary', $paymentType->count_type);
        $this->assertTrue($paymentType->is_active);
        $this->assertSame(
            [$this->storeA->id, $this->storeB->id],
            $paymentType->stores()->orderBy('code')->pluck('stores.id')->all()
        );
    }

    public function test_kode_tipe_pembayaran_harus_unik(): void
    {
        PaymentType::create([
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'description' => null,
            'order_number' => 1,
            'count_type' => 'summary',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), $this->validPayload())
            ->assertSessionHasErrors('code');
    }

    public function test_validasi_field_wajib(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.payment-types.store'), [])
            ->assertSessionHasErrors(['code', 'order_number', 'count_type', 'store_ids']);
    }

    public function test_update_menyinkronkan_gerai_dan_status_aktif(): void
    {
        $paymentType = PaymentType::create([
            'company_id' => $this->company->id,
            'code' => 'QRIS',
            'description' => null,
            'order_number' => 2,
            'count_type' => 'detail',
            'is_active' => true,
        ]);
        $paymentType->stores()->sync([$this->storeA->id]);

        $payload = $this->validPayload([
            'code' => 'QRIS',
            'order_number' => 3,
            'count_type' => 'detail',
        ]);
        unset($payload['is_active']);

        $this->actingAs($this->admin)
            ->put(route('admin.payment-types.update', $paymentType), $payload)
            ->assertRedirect(route('admin.payment-types.index'));

        $paymentType->refresh();
        $this->assertSame(3, $paymentType->order_number);
        $this->assertSame('detail', $paymentType->count_type);
        $this->assertFalse($paymentType->is_active);
        $this->assertSame(2, $paymentType->stores()->count());
    }

    public function test_hapus_tipe_pembayaran_menghapus_relasi_gerai(): void
    {
        $paymentType = PaymentType::create([
            'company_id' => $this->company->id,
            'code' => 'VOUCHER',
            'description' => null,
            'order_number' => 4,
            'count_type' => 'summary',
            'is_active' => true,
        ]);
        $paymentType->stores()->sync([$this->storeA->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.payment-types.destroy', $paymentType))
            ->assertRedirect(route('admin.payment-types.index'));

        $this->assertDatabaseMissing('payment_types', ['code' => 'VOUCHER']);
        $this->assertDatabaseCount('payment_type_store', 0);
    }
}
