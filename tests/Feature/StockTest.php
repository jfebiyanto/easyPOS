<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockAdjustment;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Support\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $storeA;

    private Store $storeB;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'allow_negative_stock' => false]);

        $this->storeA = Store::create([
            'company_id' => $this->company->id,
            'code' => 'STR001',
            'name' => 'Gerai A',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);
        $this->storeB = Store::create([
            'company_id' => $this->company->id,
            'code' => 'STR002',
            'name' => 'Gerai B',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);

        $this->owner = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->storeA->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'BEV', 'name' => 'Minuman', 'is_active' => true]);

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'product_group_id' => $group->id,
            'unit_id' => $unit->id,
            'sku' => 'SKU-1',
            'name' => 'Aqua',
            'base_price' => 4000,
            'track_stock' => true,
            'is_active' => true,
        ]);

        // Saldo awal 10 di gerai A.
        StockService::record($this->storeA->id, $this->product->id, StockLedger::TYPE_OPENING, 10);
    }

    private function balance(string $storeId): float
    {
        return StockService::balance($storeId, $this->product->id);
    }

    public function test_adjustment_draft_lalu_posting_mengubah_saldo(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.stock-adjustments.store'), [
                'store_id' => $this->storeA->id,
                'date' => now()->format('Y-m-d'),
                'reason' => 'Opname',
                'lines' => [
                    ['product_id' => $this->product->id, 'qty_after' => 7],
                ],
            ])
            ->assertRedirect(route('admin.stock-adjustments.index'));

        $adjustment = StockAdjustment::first();
        $this->assertSame(StockAdjustment::STATUS_DRAFT, $adjustment->status);
        $this->assertSame(10.0, $this->balance($this->storeA->id));

        $this->post(route('admin.stock-adjustments.post', $adjustment))
            ->assertRedirect(route('admin.stock-adjustments.index'));

        $adjustment->refresh();
        $this->assertSame(StockAdjustment::STATUS_POSTED, $adjustment->status);
        $this->assertNotNull($adjustment->number);
        $this->assertSame(7.0, $this->balance($this->storeA->id));
    }

    public function test_adjustment_yang_membuat_stok_negatif_ditolak(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.stock-adjustments.store'), [
                'store_id' => $this->storeA->id,
                'date' => now()->format('Y-m-d'),
                'lines' => [
                    ['product_id' => $this->product->id, 'qty_after' => -5],
                ],
            ]);

        $adjustment = StockAdjustment::first();

        $this->post(route('admin.stock-adjustments.post', $adjustment))
            ->assertStatus(422);

        $this->assertSame(10.0, $this->balance($this->storeA->id));
    }
}
