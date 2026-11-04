<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);
        $this->owner = User::create([
            'company_id' => $this->company->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    private function makeStore(string $code): Store
    {
        return Store::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => 'Gerai '.$code,
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);
    }

    private function storePayload(Store $store, array $overrides = []): array
    {
        return array_merge([
            'code' => $store->code,
            'name' => $store->name,
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ], $overrides);
    }

    public function test_warehouse_dibuat_dengan_kode_nama_dan_profit_center(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.warehouses.store'), ['code' => 'WH-01', 'name' => 'Gudang Satu', 'profit_center' => 'PC-JKT', 'is_active' => 1])
            ->assertRedirect(route('admin.warehouses.index'));

        $this->assertDatabaseHas('warehouses', [
            'company_id' => $this->company->id, 'code' => 'WH-01', 'name' => 'Gudang Satu', 'profit_center' => 'PC-JKT',
        ]);

        $this->post(route('admin.warehouses.store'), ['code' => 'WH-01', 'name' => 'Duplikat'])
            ->assertSessionHasErrors('code');

        $this->get(route('admin.warehouses.index'))->assertOk()->assertSee('Gudang Satu');
    }

    public function test_gerai_dihubungkan_ke_satu_warehouse_dan_warehouse_tidak_bisa_dipakai_dua_gerai(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $storeA = $this->makeStore('GR01');
        $storeB = $this->makeStore('GR02');

        $this->actingAs($this->owner)
            ->put(route('admin.stores.update', $storeA), $this->storePayload($storeA, ['warehouse_id' => $warehouse->id]))
            ->assertRedirect(route('admin.stores.index'));

        $this->assertSame($warehouse->id, $storeA->refresh()->warehouse_id);

        $this->put(route('admin.stores.update', $storeB), $this->storePayload($storeB, ['warehouse_id' => $warehouse->id]))
            ->assertSessionHasErrors('warehouse_id');

        $this->get(route('admin.stores.edit', $storeB))
            ->assertOk()
            ->assertDontSee($warehouse->code.' · ');
    }

    public function test_gerai_hanya_dapat_memakai_warehouse_retail(): void
    {
        $nonRetail = Warehouse::factory()->nonRetail()->create(['company_id' => $this->company->id]);
        $store = $this->makeStore('GR01');

        $this->actingAs($this->owner)
            ->put(route('admin.stores.update', $store), $this->storePayload($store, ['warehouse_id' => $nonRetail->id]))
            ->assertSessionHasErrors('warehouse_id');

        $this->assertNull($store->refresh()->warehouse_id);

        $this->get(route('admin.stores.edit', $store))
            ->assertOk()
            ->assertDontSee($nonRetail->code.' · ');
    }

    public function test_gerai_tetap_bisa_disimpan_bila_warehousenya_tidak_lagi_retail(): void
    {
        $warehouse = Warehouse::factory()->nonRetail()->create(['company_id' => $this->company->id]);
        $store = $this->makeStore('GR01');
        $store->update(['warehouse_id' => $warehouse->id]);

        $this->actingAs($this->owner)
            ->put(route('admin.stores.update', $store), $this->storePayload($store, ['name' => 'Gerai Baru', 'warehouse_id' => $warehouse->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Gerai Baru', $store->refresh()->name);
        $this->assertSame($warehouse->id, $store->warehouse_id);
    }

    public function test_warehouse_manual_dapat_ditandai_non_retail(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.warehouses.store'), ['code' => 'DC-01', 'name' => 'Gudang Pusat', 'is_active' => 1])
            ->assertRedirect(route('admin.warehouses.index'));

        $this->assertFalse(Warehouse::where('code', 'DC-01')->value('is_retail'));
    }

    public function test_warehouse_yang_dipakai_gerai_tidak_bisa_dihapus(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $store = $this->makeStore('GR01');
        $store->update(['warehouse_id' => $warehouse->id]);

        $this->actingAs($this->owner)
            ->delete(route('admin.warehouses.destroy', $warehouse))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
    }
}
