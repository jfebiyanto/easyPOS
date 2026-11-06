<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\PriceList;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorePriceListDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerai_menyimpan_satu_pricelist_jual_dan_satu_pricelist_beli(): void
    {
        $company = CompanyDetail::create(['name' => 'Test Co']);
        $admin = User::create([
            'company_id' => $company->id,
            'name' => 'Admin Test',
            'username' => 'admintest',
            'email' => 'admin@test.local',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
        $store = Store::create([
            'company_id' => $company->id,
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);
        $other = PriceList::create(['company_id' => $company->id, 'code' => 'PL1', 'name' => 'Umum', 'is_active' => true]);
        $default = PriceList::create(['company_id' => $company->id, 'code' => 'PL2', 'name' => 'Store', 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.stores.update', $store), [
                'code' => 'GR01',
                'name' => 'Gerai Satu',
                'pic_name' => 'PIC',
                'currency' => 'IDR',
                'sales_price_list_id' => $default->id,
                'purchase_price_list_id' => $other->id,
            ])
            ->assertRedirect(route('admin.stores.index'));

        $this->assertSame([$default->id], $store->priceLists()->pluck('price_lists.id')->all());
        $this->assertSame($other->id, $store->refresh()->purchase_price_list_id);
        $this->assertSame($default->id, $store->priceLists()->wherePivot('is_default', true)->value('price_lists.id'));

        $this->get(route('admin.stores.edit', $store))
            ->assertOk()
            ->assertSeeInOrder(['name="sales_price_list_id"', 'value="'.$default->id.'"', 'selected', 'name="purchase_price_list_id"', 'value="'.$other->id.'"', 'selected'])
            ->assertDontSee('price_list_ids[]', false);
    }
}
