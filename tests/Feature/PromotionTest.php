<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Support\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private Product $coffee;

    private Product $cookie;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'ppn_enabled' => false, 'ppn_rate' => 0]);

        $this->store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'STR001',
            'name' => 'Gerai A',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);

        $this->owner = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'BEV', 'name' => 'Minuman', 'is_active' => true]);

        $this->coffee = $this->createProduct($unit, $group, 'KOPI-1', 'Kopi Susu');
        $this->cookie = $this->createProduct($unit, $group, 'KUE-1', 'Cookies');
    }

    public function test_active_at_hanya_mengambil_promosi_aktif_dalam_periode(): void
    {
        $this->travelTo('2026-10-15 12:00:00');

        $this->createPromotion('OPEN', ['starts_at' => null, 'ends_at' => null]);
        $this->createPromotion('NOW', ['starts_at' => '2026-10-15 12:00:00', 'ends_at' => '2026-10-15 12:00:00']);
        $this->createPromotion('OFF', ['is_active' => false]);
        $this->createPromotion('FUTURE', ['starts_at' => '2026-10-15 12:00:01']);
        $this->createPromotion('PAST', ['ends_at' => '2026-10-15 11:59:59']);

        $codes = Promotion::activeAt(now())->orderBy('code')->pluck('code')->all();

        $this->assertSame(['NOW', 'OPEN'], $codes);
    }

    public function test_active_for_hanya_mengambil_promosi_milik_perusahaan(): void
    {
        $otherCompany = CompanyDetail::create(['name' => 'Other Co']);

        $this->createPromotion('MINE');
        $this->createPromotion('MINE-OFF', ['is_active' => false]);
        $this->createPromotion('THEIRS', ['company_id' => $otherCompany->id]);

        $codes = PromotionService::activeFor($this->company->id, now())->pluck('code')->all();

        $this->assertSame(['MINE'], $codes);
    }

    public function test_beli_x_gratis_y_menyimpan_aturan_dan_produk_bonus(): void
    {
        $promotion = $this->createPromotion('B2G1', [
            'type' => 'buy_x_get_y',
            'product_id' => $this->coffee->id,
            'buy_qty' => 2,
            'get_qty' => 1,
            'get_product_id' => $this->cookie->id,
            'max_applications' => 3,
        ])->fresh();

        $this->assertSame(2, $promotion->buy_qty);
        $this->assertSame(1, $promotion->get_qty);
        $this->assertSame(3, $promotion->max_applications);
        $this->assertTrue($promotion->bonusProduct->is($this->cookie));
    }

    public function test_hapus_produk_bonus_mengosongkan_referensi_promosi(): void
    {
        $promotion = $this->createPromotion('B2G1', [
            'type' => 'buy_x_get_y',
            'buy_qty' => 2,
            'get_qty' => 1,
            'get_product_id' => $this->cookie->id,
        ]);

        $this->cookie->delete();

        $this->assertNull($promotion->fresh()->get_product_id);
    }

    public function test_baris_penjualan_tetap_menyimpan_snapshot_saat_promosi_dihapus(): void
    {
        $promotion = $this->createPromotion('DISC10');

        $sale = Sale::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'business_date' => '2026-10-15',
        ]);

        $line = $sale->lines()->create([
            'product_id' => $this->coffee->id,
            'name_snapshot' => $this->coffee->name,
            'unit_price' => 20000,
            'qty' => 1,
            'promotion_id' => $promotion->id,
            'promotion_name_snapshot' => $promotion->name,
            'promotion_discount' => 2000,
            'line_total' => 18000,
        ]);

        $this->assertTrue($line->promotion->is($promotion));

        $promotion->delete();
        $line->refresh();

        $this->assertNull($line->promotion_id);
        $this->assertSame('Promo DISC10', $line->promotion_name_snapshot);
        $this->assertSame('2000.00', $line->promotion_discount);
    }

    public function test_admin_menyimpan_promosi_beli_x_gratis_y(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.promotions.store'), $this->buyXGetYPayload())
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHasNoErrors();

        $promotion = Promotion::where('code', 'B2G1')->firstOrFail();

        $this->assertSame(2, $promotion->buy_qty);
        $this->assertSame(1, $promotion->get_qty);
        $this->assertSame($this->cookie->id, $promotion->get_product_id);
        $this->assertSame(3, $promotion->max_applications);
        $this->assertSame('0.00', $promotion->value);
    }

    public function test_beli_x_gratis_y_wajib_isi_produk_jumlah_beli_dan_gratis(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.promotions.store'), $this->buyXGetYPayload(['product_id' => '', 'buy_qty' => '', 'get_qty' => '']))
            ->assertSessionHasErrors(['product_id', 'buy_qty', 'get_qty']);

        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_validasi_nilai_sesuai_tipe(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('admin.promotions.store'), $this->buyXGetYPayload(['type' => 'discount_amount', 'value' => 0]))
            ->assertSessionHasErrors('value');

        // Diskon persen berasal dari SAP B1 (Discount Group), tidak dibuat manual.
        $this->post(route('admin.promotions.store'), $this->buyXGetYPayload(['type' => 'discount_percent', 'value' => 10]))
            ->assertSessionHasErrors('type');

        $this->post(route('admin.promotions.store'), $this->buyXGetYPayload(['type' => 'special_price', 'value' => 15000, 'product_id' => '']))
            ->assertSessionHasErrors('product_id');
    }

    public function test_ganti_tipe_mengosongkan_aturan_beli_x_gratis_y(): void
    {
        $promotion = $this->createPromotion('B2G1', [
            'type' => 'buy_x_get_y',
            'product_id' => $this->coffee->id,
            'buy_qty' => 2,
            'get_qty' => 1,
            'get_product_id' => $this->cookie->id,
            'max_applications' => 3,
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.promotions.update', $promotion), $this->buyXGetYPayload(['type' => 'discount_amount', 'value' => 1000]))
            ->assertSessionHasNoErrors();

        $promotion->refresh();

        $this->assertSame('discount_amount', $promotion->type);
        $this->assertNull($promotion->buy_qty);
        $this->assertNull($promotion->get_qty);
        $this->assertNull($promotion->get_product_id);
        $this->assertNull($promotion->max_applications);
    }

    public function test_hapus_centang_aktif_menonaktifkan_promosi(): void
    {
        $promotion = $this->createPromotion('DISC10');

        $payload = $this->buyXGetYPayload(['code' => 'DISC10', 'type' => 'discount_amount', 'value' => 1000]);
        unset($payload['is_active']);

        $this->actingAs($this->owner)
            ->put(route('admin.promotions.update', $promotion), $payload)
            ->assertSessionHasNoErrors();

        $this->assertFalse($promotion->fresh()->is_active);
    }

    public function test_daftar_promosi_menampilkan_ringkasan_aturan(): void
    {
        $this->createPromotion('B2G1', [
            'type' => 'buy_x_get_y',
            'product_id' => $this->coffee->id,
            'buy_qty' => 2,
            'get_qty' => 1,
            'get_product_id' => $this->cookie->id,
            'max_applications' => 3,
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('Kopi Susu')
            ->assertSee('Buy 2 Get 1 Free Cookies · max. 3×');
    }

    public function test_api_memakai_validasi_yang_sama(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/promotions', $this->buyXGetYPayload(['get_qty' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('get_qty');

        $this->postJson('/api/promotions', $this->buyXGetYPayload())
            ->assertCreated()
            ->assertJsonPath('data.buy_qty', 2);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buyXGetYPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'B2G1',
            'name' => 'Beli 2 Gratis 1',
            'type' => 'buy_x_get_y',
            'product_id' => $this->coffee->id,
            'buy_qty' => 2,
            'get_qty' => 1,
            'get_product_id' => $this->cookie->id,
            'max_applications' => 3,
            'is_active' => '1',
        ], $overrides);
    }

    private function createProduct(Unit $unit, ProductGroup $group, string $sku, string $name): Product
    {
        return Product::create([
            'company_id' => $this->company->id,
            'product_group_id' => $group->id,
            'unit_id' => $unit->id,
            'sku' => $sku,
            'name' => $name,
            'base_price' => 20000,
            'track_stock' => false,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPromotion(string $code, array $attributes = []): Promotion
    {
        return Promotion::create($attributes + [
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => "Promo {$code}",
            'type' => 'discount_percent',
            'value' => 10,
            'is_active' => true,
        ]);
    }
}
