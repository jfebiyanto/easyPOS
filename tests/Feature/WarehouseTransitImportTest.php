<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\SapSyncRun;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WarehouseTransitImportTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $owner;

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

        $store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'STR001',
            'name' => 'Store Satu',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);

        $this->owner = User::create([
            'company_id' => $this->company->id,
            'store_id' => $store->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    public function test_impor_warehouse_membaca_udf_transit(): void
    {
        Http::fake([
            '*/Login' => Http::response(['SessionId' => 'abc'], 200),
            '*/Logout' => Http::response('', 204),
            '*/Warehouses*' => Http::response(['value' => [
                ['WarehouseCode' => 'G.001.MN', 'WarehouseName' => 'Gerai Satu', 'Inactive' => 'tNO', 'U_SOL_RETAIL' => 'Y', 'U_SOL_WHTR' => 'G.001.IT'],
                ['WarehouseCode' => 'G.001.IT', 'WarehouseName' => 'Transit Gerai Satu', 'Inactive' => 'tNO', 'U_SOL_RETAIL' => '', 'U_SOL_ITWH' => 'Y'],
            ]]),
            '*' => Http::response(['value' => []]),
        ]);

        $this->actingAs($this->owner)->post(route('admin.sap-import.run'), ['mode' => 'initial']);

        $this->assertSame('done', SapSyncRun::first()->summary['warehouse']['status']);

        $main = Warehouse::where('code', 'G.001.MN')->firstOrFail();
        $transit = Warehouse::where('code', 'G.001.IT')->firstOrFail();

        $this->assertSame('G.001.IT', $main->transit_warehouse_code);
        $this->assertFalse($main->is_transit);
        $this->assertTrue($transit->is_transit);
        $this->assertFalse($transit->is_retail);
        $this->assertNull($transit->transit_warehouse_code);
        $this->assertSame('G.001.IT', $main->transitWarehouse()?->code);
    }

    public function test_gudang_in_transit_tidak_ikut_pilihan_warehouse_store(): void
    {
        Warehouse::factory()->create(['company_id' => $this->company->id, 'code' => 'G.009.MN', 'name' => 'Gerai Sembilan']);
        Warehouse::factory()->transit()->create(['company_id' => $this->company->id, 'code' => 'G.009.IT', 'name' => 'Transit Sembilan']);

        $this->actingAs($this->owner)
            ->get(route('admin.stores.create'))
            ->assertOk()
            ->assertSee('G.009.MN')
            ->assertDontSee('G.009.IT');
    }
}
