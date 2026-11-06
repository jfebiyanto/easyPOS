<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockAdjustment;
use App\Models\StockLedger;
use App\Models\StockTransfer;
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

    public function test_transfer_memindahkan_stok_antar_gerai(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.stock-transfers.store'), [
                'from_store_id' => $this->storeA->id,
                'to_store_id' => $this->storeB->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 4],
                ],
            ])
            ->assertRedirect(route('admin.stock-transfers.index'));

        $transfer = StockTransfer::first();
        $this->assertSame(StockTransfer::STATUS_DRAFT, $transfer->status);

        // Kirim: stok keluar dari gerai A.
        $this->post(route('admin.stock-transfers.ship', $transfer))
            ->assertRedirect(route('admin.stock-transfers.index'));

        $this->assertSame(6.0, $this->balance($this->storeA->id));
        $this->assertSame(0.0, $this->balance($this->storeB->id));

        // Terima penuh.
        $transfer->refresh();
        $line = $transfer->lines()->first();

        $this->post(route('admin.stock-transfers.receive', $transfer), [
            'lines' => [
                ['id' => $line->id, 'qty_received' => 4, 'variance_reason' => ''],
            ],
        ])->assertRedirect(route('admin.stock-transfers.index'));

        $this->assertSame(6.0, $this->balance($this->storeA->id));
        $this->assertSame(4.0, $this->balance($this->storeB->id));
    }

    private function makeManager(Store $store, string $username): User
    {
        return User::create([
            'company_id' => $this->company->id, 'store_id' => $store->id, 'name' => ucfirst($username), 'username' => $username,
            'role' => User::ROLE_STORE_MANAGER, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function draftTransferAtoB(int $qty = 4): StockTransfer
    {
        $this->actingAs($this->makeManager($this->storeA, 'managera'))
            ->post(route('admin.stock-transfers.store'), [
                'to_store_id' => $this->storeB->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => $qty]],
            ])->assertRedirect(route('admin.stock-transfers.index'));

        return StockTransfer::firstOrFail();
    }

    public function test_hanya_sm_gerai_asal_yang_mengirim_dan_membatalkan_transfer(): void
    {
        $transfer = $this->draftTransferAtoB();
        $this->assertSame($this->storeA->id, $transfer->from_store_id);
        $managerB = $this->makeManager($this->storeB, 'managerb');

        $this->actingAs($managerB)->post(route('admin.stock-transfers.ship', $transfer))->assertForbidden();
        $this->actingAs($managerB)->post(route('admin.stock-transfers.cancel', $transfer))->assertForbidden();
        $this->assertSame(StockTransfer::STATUS_DRAFT, $transfer->refresh()->status);
        $this->assertSame(10.0, $this->balance($this->storeA->id));
    }

    public function test_hanya_sm_gerai_tujuan_yang_menerima_transfer(): void
    {
        $transfer = $this->draftTransferAtoB();
        $managerA = User::where('username', 'managera')->firstOrFail();
        $managerB = $this->makeManager($this->storeB, 'managerb');

        $this->actingAs($managerA)->post(route('admin.stock-transfers.ship', $transfer))->assertRedirect();
        $line = $transfer->lines()->firstOrFail();

        $this->actingAs($managerA)->get(route('admin.stock-transfers.receive-form', $transfer))->assertForbidden();
        $this->actingAs($managerA)->post(route('admin.stock-transfers.receive', $transfer), [
            'lines' => [['id' => $line->id, 'qty_received' => 4, 'variance_reason' => '']],
        ])->assertForbidden();
        $this->assertSame(0.0, $this->balance($this->storeB->id));

        $this->actingAs($managerB)->get(route('admin.stock-transfers.receive-form', $transfer))->assertOk();
        $this->actingAs($managerB)->post(route('admin.stock-transfers.receive', $transfer), [
            'lines' => [['id' => $line->id, 'qty_received' => 4, 'variance_reason' => '']],
        ])->assertRedirect();
        $this->assertSame(4.0, $this->balance($this->storeB->id));
    }

    public function test_kedua_sm_melihat_transfer_di_daftar_dan_gerai_asal_selalu_miliknya(): void
    {
        $this->draftTransferAtoB();
        $managerB = $this->makeManager($this->storeB, 'managerb');

        $this->actingAs($managerB)->get(route('admin.stock-transfers.index'))->assertOk()->assertViewHas('transfers', fn ($p) => $p->count() === 1);

        // SM tidak dapat membuat transfer dari gerai lain walau mengirim from_store_id gerai A.
        $this->actingAs($managerB)->post(route('admin.stock-transfers.store'), [
            'from_store_id' => $this->storeA->id,
            'to_store_id' => $this->storeA->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ]);
        $this->assertSame(0, StockTransfer::where('from_store_id', $this->storeA->id)->where('created_by', $managerB->id)->count());
    }

    public function test_transfer_tidak_bisa_dibatalkan_setelah_dikirim(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.stock-transfers.store'), [
                'from_store_id' => $this->storeA->id,
                'to_store_id' => $this->storeB->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            ]);

        $transfer = StockTransfer::first();
        $this->post(route('admin.stock-transfers.ship', $transfer));

        $this->post(route('admin.stock-transfers.cancel', $transfer))->assertStatus(422);
        $this->assertSame(StockTransfer::STATUS_SHIPPED, $transfer->refresh()->status);
    }

    public function test_selisih_penerimaan_wajib_alasan(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.stock-transfers.store'), [
                'from_store_id' => $this->storeA->id,
                'to_store_id' => $this->storeB->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            ]);

        $transfer = StockTransfer::first();
        $this->post(route('admin.stock-transfers.ship', $transfer));
        $line = $transfer->lines()->first();

        // Qty diterima berbeda tanpa alasan → error.
        $this->post(route('admin.stock-transfers.receive', $transfer), [
            'lines' => [['id' => $line->id, 'qty_received' => 4, 'variance_reason' => '']],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0.0, $this->balance($this->storeB->id));
    }
}
