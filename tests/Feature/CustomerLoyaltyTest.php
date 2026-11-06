<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\MemberLevel;
use App\Models\PaymentType;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Support\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    private User $cashier;

    private Product $product;

    private PaymentType $cash;

    private MemberLevel $silver;

    private MemberLevel $gold;

    private Customer $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co',
            'ppn_enabled' => false,
            'ppn_rate' => 0,
            'allow_negative_stock' => true,
        ]);

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

        $this->cashier = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Kasir',
            'username' => 'kasir1',
            'role' => User::ROLE_CASHIER,
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
            'base_price' => 10000,
            'track_stock' => true,
            'is_active' => true,
        ]);

        $priceList = PriceList::create([
            'company_id' => $this->company->id,
            'code' => 'DEFAULT',
            'name' => 'Default',
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->store->priceLists()->sync([$priceList->id => ['is_default' => true]]);

        $this->cash = PaymentType::create([
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'description' => 'Tunai',
            'order_number' => 1,
            'count_type' => 'summary',
            'is_active' => true,
        ]);
        $this->cash->stores()->sync([$this->store->id]);

        $this->silver = MemberLevel::create(['company_id' => $this->company->id, 'name' => 'Silver', 'min_points' => 0, 'earn_amount_per_point' => 10000, 'order' => 0, 'is_active' => true]);
        $this->gold = MemberLevel::create(['company_id' => $this->company->id, 'name' => 'Gold', 'min_points' => 100, 'earn_amount_per_point' => 8000, 'order' => 1, 'is_active' => true]);

        $this->member = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUST-00001',
            'name' => 'Member Satu',
            'phone' => '081200000001',
            'is_member' => true,
            'points_balance' => 0,
            'member_level_id' => $this->silver->id,
            'is_active' => true,
        ]);

        StockService::record($this->store->id, $this->product->id, StockLedger::TYPE_OPENING, 100);
    }

    private function sell(float $qty = 1, ?string $customerId = null): Sale
    {
        $total = 10000 * $qty;

        $this->actingAs($this->cashier)
            ->postJson('/pos/sales', [
                'client_uuid' => (string) Str::uuid(),
                'origin' => 'online',
                'customer_id' => $customerId,
                'lines' => [['product_id' => $this->product->id, 'qty' => $qty]],
                'payments' => [['payment_type_id' => $this->cash->id, 'amount' => $total, 'status' => 'confirmed']],
            ])
            ->assertStatus(201);

        return Sale::latest('created_at')->first();
    }

    public function test_pendaftaran_customer_via_phone_dan_duplikat_ditolak(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/pos/customers', ['name' => 'Baru', 'phone' => '081299999999'])
            ->assertStatus(201);

        $this->assertDatabaseHas('customers', ['phone' => '081299999999']);

        $this->actingAs($this->cashier)
            ->postJson('/pos/customers', ['name' => 'Duplikat', 'phone' => '081299999999'])
            ->assertStatus(422);
    }

    public function test_pendaftaran_customer_via_kode(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/pos/customers', ['name' => 'Tanpa HP', 'code' => 'KODE-X'])
            ->assertStatus(201);

        $this->assertDatabaseHas('customers', ['code' => 'KODE-X']);
    }

    public function test_admin_dapat_menambah_pelanggan(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.customers.store'), [
                'name' => 'Pelanggan Admin',
                'phone' => '081277777777',
                'is_member' => '1',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.customers.index'));

        $this->assertDatabaseHas('customers', ['phone' => '081277777777']);
    }

    public function test_daftar_pelanggan_diurutkan_berdasarkan_kode_tanpa_kolom_transaksi(): void
    {
        foreach (['ZZ-002', 'AA-001'] as $code) {
            Customer::create(['company_id' => $this->company->id, 'code' => $code, 'name' => "Pelanggan {$code}", 'is_active' => true]);
        }

        $this->actingAs($this->owner)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSeeInOrder(['AA-001', 'ZZ-002'])
            ->assertDontSee('<th>Transaksi</th>', false);
    }

    public function test_transaksi_member_mendapat_poin_sesuai_tarif_level(): void
    {
        $sale = $this->sell(1, $this->member->id);

        $this->assertSame($this->member->id, $sale->customer_id, 'customer_id tidak tersimpan');
        $this->assertSame(1.0, (float) $sale->points_earned);
        $this->assertSame(1.0, (float) $this->member->refresh()->points_balance);
    }

    public function test_transaksi_tanpa_member_tidak_mendapat_poin(): void
    {
        $sale = $this->sell(1, null);
        $this->assertSame(0.0, (float) $sale->points_earned);
    }

    public function test_walk_in_non_member_dicatat_atas_pelanggan_retail_default_tanpa_poin(): void
    {
        $retail = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'RETAIL',
            'name' => 'Pelanggan Retail',
            'is_member' => false,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.stores.update', $this->store), [
                'code' => $this->store->code,
                'name' => $this->store->name,
                'pic_name' => 'PIC',
                'currency' => 'IDR',
                'default_customer_id' => $this->member->id,
            ])
            ->assertSessionHasErrors('default_customer_id');

        $this->put(route('admin.stores.update', $this->store), [
            'code' => $this->store->code,
            'name' => $this->store->name,
            'pic_name' => 'PIC',
            'currency' => 'IDR',
            'is_active' => '1',
            'default_customer_id' => $retail->id,
        ])->assertRedirect(route('admin.stores.index'));

        $walkIn = $this->sell(1, null);
        $this->assertSame($retail->id, $walkIn->customer_id);
        $this->assertSame(0.0, (float) $walkIn->points_earned);
        $this->assertSame(0.0, (float) $retail->refresh()->points_balance);

        // Bila dipilih member, tetap atas nama member tersebut.
        $this->sell(1, $this->member->id);
        $this->assertSame(1, Sale::where('customer_id', $this->member->id)->count());
        $this->assertSame(1, Sale::where('customer_id', $retail->id)->count());

        // Pelanggan retail default tidak bisa dijadikan member.
        $this->put(route('admin.customers.update', $retail), [
            'name' => 'Pelanggan Retail',
            'code' => 'RETAIL',
            'is_member' => '1',
            'is_active' => '1',
        ]);
        $this->assertFalse($retail->refresh()->is_member);
    }

    public function test_level_naik_otomatis_saat_ambang_terlewati(): void
    {
        $this->member->update(['points_balance' => 99, 'member_level_id' => $this->silver->id]);

        $this->sell(2, $this->member->id); // 20.000 → 2 poin (tarif Silver 10.000)

        $this->member->refresh();
        $this->assertSame(101.0, (float) $this->member->points_balance);
        $this->assertSame($this->gold->id, $this->member->member_level_id);
    }

    public function test_retur_mengurangi_poin(): void
    {
        $sale = $this->sell(1, $this->member->id);
        $line = $sale->lines()->first();

        $this->actingAs($this->cashier)
            ->postJson('/pos/returns', [
                'sale_id' => $sale->id,
                'refund_method' => 'cash',
                'lines' => [['sale_line_id' => $line->id, 'qty' => 1]],
            ])
            ->assertStatus(201);

        $this->assertSame(0.0, (float) $this->member->refresh()->points_balance);
    }

    public function test_void_mengurangi_poin(): void
    {
        $sale = $this->sell(1, $this->member->id);

        $this->actingAs($this->cashier)
            ->postJson("/pos/sales/{$sale->id}/void", ['reason' => 'salah'])
            ->assertOk();

        $this->assertSame(0.0, (float) $this->member->refresh()->points_balance);
    }

    public function test_member_level_dapat_dilihat_store_manager_tetapi_hanya_owner_yang_mengubah(): void
    {
        $manager = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Manager',
            'username' => 'manager1',
            'role' => User::ROLE_STORE_MANAGER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->actingAs($manager)->get(route('admin.member-levels.index'))->assertOk();
        $this->actingAs($manager)->post(route('admin.member-levels.store'), ['name' => 'Gold'])->assertForbidden();
        $this->actingAs($this->owner)->get(route('admin.member-levels.index'))->assertOk();
    }

    public function test_keterangan_masa_berlaku_poin_tampil_di_bawah_input(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.member-levels.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '<label for="expiry_days">Masa berlaku poin (hari)</label>',
                'id="expiry_days"',
                '<div class="hint">Kosongkan jika poin tanpa kedaluwarsa.</div>',
            ], false);
    }
}
