<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerGroupTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);
        $this->store = Store::create(['company_id' => $this->company->id, 'code' => 'GR01', 'name' => 'Store', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $this->owner = $this->user('owner1', User::ROLE_OWNER);
    }

    private function user(string $username, string $role): User
    {
        return User::create([
            'company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => $username,
            'username' => $username, 'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function group(string $code, array $attributes = []): CustomerGroup
    {
        return CustomerGroup::create($attributes + ['company_id' => $this->company->id, 'code' => $code, 'name' => "Grup {$code}", 'is_active' => true]);
    }

    public function test_daftar_menampilkan_grup_hasil_impor_sap_dan_jumlah_pelanggan(): void
    {
        $retail = $this->group('102', ['name' => 'RETAIL', 'source' => CustomerGroup::SOURCE_SAP, 'is_default' => true]);
        Customer::create(['company_id' => $this->company->id, 'code' => 'CR00001', 'name' => 'Umum', 'customer_group_id' => $retail->id, 'is_active' => true]);

        $this->actingAs($this->owner)
            ->get(route('admin.customer-groups.index'))
            ->assertOk()
            ->assertSeeInOrder(['102', 'Default', 'RETAIL', 'SAP B1', '1']);
    }

    public function test_grup_manual_dapat_dibuat_diubah_dan_dihapus(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.customer-groups.store'), ['code' => 'VIP', 'name' => 'Pelanggan VIP', 'is_active' => 1])
            ->assertRedirect(route('admin.customer-groups.index'));
        $group = CustomerGroup::where('code', 'VIP')->firstOrFail();
        $this->assertSame(CustomerGroup::SOURCE_MANUAL, $group->source);

        $this->actingAs($this->owner)
            ->put(route('admin.customer-groups.update', $group), ['code' => 'VIP', 'name' => 'VIP Gold', 'is_active' => 1])
            ->assertRedirect();
        $this->assertSame('VIP Gold', $group->fresh()->name);

        $this->actingAs($this->owner)->delete(route('admin.customer-groups.destroy', $group))->assertRedirect();
        $this->assertModelMissing($group);
    }

    public function test_grup_dari_sap_hanya_penanda_default_yang_dapat_diubah(): void
    {
        $group = $this->group('102', ['name' => 'RETAIL', 'source' => CustomerGroup::SOURCE_SAP]);

        $this->actingAs($this->owner)
            ->put(route('admin.customer-groups.update', $group), ['code' => 'X', 'name' => 'Diubah', 'is_default' => 1])
            ->assertRedirect();

        $group->refresh();
        $this->assertSame(['102', 'RETAIL', true, true], [$group->code, $group->name, $group->is_active, $group->is_default]);

        $this->actingAs($this->owner)->delete(route('admin.customer-groups.destroy', $group))->assertForbidden();
    }

    public function test_hanya_satu_grup_default_dan_grup_nonaktif_tidak_bisa_jadi_default(): void
    {
        $first = $this->group('100', ['is_default' => true]);
        $second = $this->group('102');
        $inactive = $this->group('103', ['is_active' => false]);

        $this->actingAs($this->owner)
            ->put(route('admin.customer-groups.update', $second), ['code' => '102', 'name' => 'Grup 102', 'is_active' => 1, 'is_default' => 1]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        $this->actingAs($this->owner)
            ->put(route('admin.customer-groups.update', $inactive), ['code' => '103', 'name' => 'Grup 103', 'is_default' => 1])
            ->assertStatus(422);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_grup_yang_dipakai_pelanggan_tidak_dapat_dihapus(): void
    {
        $group = $this->group('VIP');
        Customer::create(['company_id' => $this->company->id, 'code' => 'C1', 'name' => 'Budi', 'customer_group_id' => $group->id, 'is_active' => true]);

        $this->actingAs($this->owner)->delete(route('admin.customer-groups.destroy', $group))->assertSessionHas('error');
        $this->assertModelExists($group);
    }

    public function test_grup_default_menjadi_nilai_awal_pelanggan_baru_di_admin_dan_pos(): void
    {
        $this->group('100');
        $default = $this->group('102', ['name' => 'RETAIL', 'is_default' => true]);

        $this->actingAs($this->owner)
            ->get(route('admin.customers.create'))
            ->assertOk()
            ->assertSee('value="'.$default->id.'" selected', false);

        $this->actingAs($this->user('kasir1', User::ROLE_CASHIER))
            ->postJson(route('pos.customers.store'), ['name' => 'Citra', 'phone' => '0814'])
            ->assertCreated();

        $this->assertSame($default->id, Customer::where('name', 'Citra')->value('customer_group_id'));
    }

    public function test_hanya_owner_yang_dapat_mengelola_grup_pelanggan(): void
    {
        $this->actingAs($this->user('kasir1', User::ROLE_CASHIER))
            ->get(route('admin.customer-groups.index'))
            ->assertForbidden();
    }
}
