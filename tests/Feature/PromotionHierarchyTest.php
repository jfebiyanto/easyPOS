<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);
        $store = Store::create(['company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);

        $this->owner = User::create([
            'company_id' => $this->company->id, 'store_id' => $store->id, 'name' => 'Owner', 'username' => 'owner1',
            'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password',
        ]);
        $this->manager = User::create([
            'company_id' => $this->company->id, 'store_id' => $store->id, 'name' => 'Manager', 'username' => 'sm1',
            'role' => User::ROLE_STORE_MANAGER, 'is_active' => true, 'password' => 'password',
        ]);
    }

    public function test_urutan_bawaan_menaruh_diskon_sap_di_atas_promosi_easypos(): void
    {
        $this->assertSame(
            ['sap_period_volume', 'sap_discount_group', 'special_price', 'discount_amount', 'buy_x_get_y'],
            $this->company->promotionHierarchy(),
        );
    }

    public function test_owner_menyimpan_urutan_dan_kunci_yang_hilang_dirapikan_ke_bawah(): void
    {
        $this->company->update(['promotion_hierarchy' => ['buy_x_get_y', 'tidak-dikenal', 'sap_discount_group']]);

        $this->assertSame(
            ['buy_x_get_y', 'sap_discount_group', 'sap_period_volume', 'special_price', 'discount_amount'],
            $this->company->fresh()->promotionHierarchy(),
        );
    }

    public function test_halaman_hierarki_menampilkan_urutan_tersimpan(): void
    {
        $this->company->update(['promotion_hierarchy' => ['special_price', 'sap_period_volume']]);

        $this->actingAs($this->owner)->get(route('admin.promotion-hierarchy.edit'))
            ->assertOk()
            ->assertSeeInOrder(['Special Price (easyPOS)', 'Period & Volume Discount (SAP B1)', 'Discount Group (SAP B1)']);
    }

    public function test_owner_dapat_mengubah_hierarki(): void
    {
        $order = ['buy_x_get_y', 'discount_amount', 'special_price', 'sap_discount_group', 'sap_period_volume'];

        $this->actingAs($this->owner)->put(route('admin.promotion-hierarchy.update'), ['order' => $order])
            ->assertRedirect(route('admin.promotion-hierarchy.edit'))
            ->assertSessionHas('success');

        $this->assertSame($order, $this->company->fresh()->promotionHierarchy());
    }

    public function test_hierarki_harus_memuat_semua_sumber_tepat_sekali(): void
    {
        $this->actingAs($this->owner);

        $this->put(route('admin.promotion-hierarchy.update'), ['order' => ['special_price', 'sap_period_volume']])
            ->assertSessionHasErrors('order');
        $this->put(route('admin.promotion-hierarchy.update'), ['order' => ['special_price', 'special_price', 'discount_amount', 'buy_x_get_y', 'sap_period_volume']])
            ->assertSessionHasErrors('order.1');
        $this->put(route('admin.promotion-hierarchy.update'), ['order' => ['x', 'sap_discount_group', 'discount_amount', 'buy_x_get_y', 'sap_period_volume']])
            ->assertSessionHasErrors('order.0');

        $this->assertNull($this->company->fresh()->promotion_hierarchy);
    }

    public function test_store_manager_tidak_boleh_mengubah_hierarki(): void
    {
        $this->actingAs($this->manager)->get(route('admin.promotion-hierarchy.edit'))->assertOk();
        $this->put(route('admin.promotion-hierarchy.update'), ['order' => array_keys(Promotion::HIERARCHY_SOURCES)])->assertForbidden();
    }

    public function test_diskon_sap_hanya_baca_di_daftar_promosi(): void
    {
        $promotion = Promotion::create([
            'company_id' => $this->company->id, 'code' => 'DG-1', 'name' => 'Discount Group #1', 'type' => 'discount_percent', 'value' => 15,
            'source' => Promotion::SOURCE_SAP, 'sap_ref' => 'DG|1|0', 'customer_scope' => Promotion::CUSTOMER_GROUP, 'customer_ref' => '102', 'is_active' => true,
            'starts_at' => '2026-11-04 17:00:00', 'ends_at' => '2026-11-08 16:59:59',
        ]);
        $this->company->update(['timezone' => 'Asia/Jakarta']);

        $this->actingAs($this->owner)->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('05/11/2026 ~ 08/11/2026')
            ->assertSee('SAP B1')
            ->assertSee('Read-only')
            ->assertSee('customer group 102');

        $this->get(route('admin.promotions.edit', $promotion))->assertForbidden();
        $this->put(route('admin.promotions.update', $promotion), ['code' => 'X', 'name' => 'X', 'type' => 'discount_amount', 'value' => 1])->assertForbidden();
        $this->delete(route('admin.promotions.destroy', $promotion))->assertForbidden();

        $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'code' => 'DG-1']);
    }
}
