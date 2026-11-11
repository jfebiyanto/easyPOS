<?php

namespace Tests\Feature;

use App\Models\CashierShift;
use App\Models\CompanyDetail;
use App\Models\Currency;
use App\Models\PaymentType;
use App\Models\PosSession;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\StoreClosure;
use App\Models\Unit;
use App\Models\User;
use App\Support\ShiftService;
use App\Support\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CashierShiftTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    private User $cashier;

    private Product $product;

    private PaymentType $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co', 'ppn_enabled' => false, 'ppn_rate' => 0, 'timezone' => 'Asia/Jakarta', 'allow_negative_stock' => false, 'base_currency_code' => 'IDR']);
        $this->store = Store::create(['company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $this->owner = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Owner', 'username' => 'owner1', 'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password']);
        $this->cashier = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Kasir', 'username' => 'kasir1', 'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password']);

        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'BEV', 'name' => 'Minuman', 'is_active' => true]);
        $this->product = Product::create(['company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id, 'sku' => 'SKU-1', 'name' => 'Aqua', 'base_price' => 10000, 'track_stock' => true, 'is_active' => true]);

        $priceList = PriceList::create(['company_id' => $this->company->id, 'code' => 'DEFAULT', 'name' => 'Default', 'is_default' => true, 'is_active' => true]);
        $this->store->priceLists()->sync([$priceList->id => ['is_default' => true]]);

        $this->cash = PaymentType::create(['company_id' => $this->company->id, 'code' => 'CASH', 'description' => 'Tunai', 'order_number' => 1, 'count_type' => 'detail', 'is_active' => true]);
        $this->cash->stores()->sync([$this->store->id]);

        $idr = Currency::create(['company_id' => $this->company->id, 'code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);
        $idr->denominations()->create(['value' => 100000, 'is_active' => true]);
        $idr->denominations()->create(['value' => 50000, 'is_active' => true]);

        StockService::record($this->store->id, $this->product->id, StockLedger::TYPE_OPENING, 100);
    }

    public function test_modal_standar_toko_default_nol_dan_bisa_diubah(): void
    {
        $this->assertSame(0.0, $this->store->fresh()->openingFloatStandard());

        $this->store->update(['opening_float' => 200000]);

        $this->assertSame(200000.0, $this->store->fresh()->openingFloatStandard());
    }

    public function test_modal_standar_diatur_lewat_form_toko(): void
    {
        $payload = ['code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR', 'opening_float' => 150000];

        $this->actingAs($this->owner)->put(route('admin.stores.update', $this->store), $payload)->assertRedirect();

        $this->assertSame(150000.0, $this->store->fresh()->openingFloatStandard());

        $this->actingAs($this->owner)->put(route('admin.stores.update', $this->store), ['opening_float' => -1] + $payload)->assertSessionHasErrors('opening_float');
    }

    private function floatCount(int $hundreds = 0, int $fifties = 0, ?string $remark = null): array
    {
        return ['denominations' => [100000 => $hundreds, 50000 => $fifties], 'remark' => $remark];
    }

    public function test_open_store_membuat_baris_buka_dan_shift_dengan_modal_dari_denominasi(): void
    {
        $this->store->update(['opening_float' => 200000]);

        $shift = ShiftService::openStore($this->store, $this->cashier, $this->floatCount(hundreds: 2));

        $closure = StoreClosure::firstOrFail();
        $this->assertSame($this->cashier->id, $closure->opened_by);
        $this->assertTrue(StoreClosure::isOpen($this->store->id, company_today()));
        $this->assertSame(200000.0, (float) $shift->opening_float);
        $this->assertSame(200000.0, (float) $shift->opening_float_expected);
        $this->assertSame(0.0, (float) $shift->opening_float_variance);
        $this->assertTrue($shift->opened_store);
        $this->assertSame(1, $shift->denominations()->count());
        $this->assertSame(200000.0, (float) $shift->denominations()->first()->subtotal);
    }

    public function test_open_store_kedua_ditolak_dan_kasir_kedua_start_shift(): void
    {
        ShiftService::openStore($this->store, $this->cashier, $this->floatCount());
        $second = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Kasir 2', 'username' => 'kasir2', 'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password']);

        try {
            ShiftService::openStore($this->store, $second, $this->floatCount());
            $this->fail('Open Store kedua harus ditolak.');
        } catch (\DomainException) {
            $this->assertSame(0, CashierShift::where('user_id', $second->id)->count());
        }

        $shift = ShiftService::startShift($this->store, $second, $this->floatCount());

        $this->assertFalse($shift->opened_store);
        $this->assertSame(1, StoreClosure::count());
        $this->assertSame(2, ShiftService::activeShifts($this->store->id)->count());
    }

    public function test_variance_tanpa_remark_ditolak_dan_dengan_remark_tercatat(): void
    {
        $this->store->update(['opening_float' => 200000]);

        try {
            ShiftService::openStore($this->store, $this->cashier, $this->floatCount(fifties: 3));
            $this->fail('Selisih modal tanpa remark harus ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('remark', $e->errors());
        }
        $this->assertSame(0, StoreClosure::count());
        $this->assertSame(0, CashierShift::count());

        $shift = ShiftService::openStore($this->store, $this->cashier, $this->floatCount(fifties: 3, remark: 'Uang 50 ribu kurang satu lembar'));

        $this->assertSame(-50000.0, (float) $shift->opening_float_variance);
        $this->assertSame('Uang 50 ribu kurang satu lembar', $shift->opening_float_remark);
    }

    public function test_nominal_nol_diperbolehkan_bila_standar_nol(): void
    {
        $shift = ShiftService::openStore($this->store, $this->cashier, $this->floatCount());

        $this->assertSame(0.0, (float) $shift->opening_float);
    }

    public function test_count_type_summary_memakai_nominal(): void
    {
        $this->cash->update(['count_type' => 'summary']);
        $this->store->update(['opening_float' => 200000]);

        $shift = ShiftService::openStore($this->store, $this->cashier, ['amount' => 200000]);

        $this->assertSame(200000.0, (float) $shift->opening_float);
        $this->assertSame(0, $shift->denominations()->count());
    }

    public function test_start_shift_ditolak_setelah_eod(): void
    {
        StoreClosure::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'business_date' => company_today(), 'opened_at' => now(), 'closed_at' => now()]);

        $this->expectException(\DomainException::class);
        ShiftService::startShift($this->store, $this->cashier, $this->floatCount());
    }

    public function test_akhir_shift_tidak_menutup_toko_dan_kasir_bisa_start_lagi(): void
    {
        ShiftService::openStore($this->store, $this->cashier, $this->floatCount());

        $ended = ShiftService::endShift($this->cashier, CashierShift::ENDED_LOGOUT);

        $this->assertSame('logout', $ended->ended_reason);
        $this->assertTrue(StoreClosure::isOpen($this->store->id, company_today()));
        $this->assertFalse(ShiftService::hasActiveShift($this->cashier, $this->store->id));

        $again = ShiftService::startShift($this->store, $this->cashier, $this->floatCount());
        $this->assertTrue(ShiftService::hasActiveShift($this->cashier, $this->store->id));
        $this->assertNotSame($ended->id, $again->id);
    }

    public function test_end_active_shifts_menandai_closed_by_admin(): void
    {
        ShiftService::openStore($this->store, $this->cashier, $this->floatCount());

        $this->assertSame(1, ShiftService::endActiveShifts($this->store->id, CashierShift::ENDED_CLOSED_BY_ADMIN));
        $this->assertSame('closed_by_admin', CashierShift::firstOrFail()->ended_reason);
    }

    public function test_shift_kedaluwarsa_bila_sesi_pos_kasir_mati(): void
    {
        $shift = ShiftService::openStore($this->store, $this->cashier, $this->floatCount());
        PosSession::create(['company_id' => $this->company->id, 'user_id' => $this->cashier->id, 'store_id' => $this->store->id, 'session_hash' => 'x', 'started_at' => now()->subMinutes(10), 'last_seen_at' => now()->subMinutes(10)]);

        ShiftService::expireStaleShifts($this->store->id);

        $this->assertSame('expired', $shift->fresh()->ended_reason);
    }

    private function saleBody(string $origin = 'online'): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'origin' => $origin,
            'store_id' => $this->store->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            'payments' => [['payment_type_id' => $this->cash->id, 'amount' => 10000, 'status' => 'confirmed']],
        ];
    }

    public function test_penjualan_online_ditolak_sebelum_toko_dibuka_dan_tanpa_shift_lalu_diterima_setelah_open(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->saleBody())->assertStatus(422);

        $second = User::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Kasir 2', 'username' => 'kasir2', 'role' => User::ROLE_CASHIER, 'is_active' => true, 'password' => 'password']);
        ShiftService::openStore($this->store, $second, $this->floatCount());
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->saleBody())->assertStatus(422);

        $this->actingAs($this->cashier)->postJson('/pos/shift/start', $this->floatCount())->assertOk();
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->saleBody())->assertCreated();
    }

    public function test_penjualan_offline_tetap_diterima_tanpa_shift_dan_setelah_eod(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->saleBody('offline'))->assertCreated();

        StoreClosure::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'business_date' => company_today(), 'opened_at' => now(), 'closed_at' => now()]);
        $this->actingAs($this->cashier)->postJson('/pos/sales', $this->saleBody('offline'))->assertCreated();
    }

    public function test_endpoint_open_store_memvalidasi_remark_dan_membuka_toko(): void
    {
        $this->store->update(['opening_float' => 200000]);

        $this->actingAs($this->cashier)->postJson('/pos/store/open', $this->floatCount(hundreds: 1))
            ->assertStatus(422)->assertJsonValidationErrors('remark');

        $this->actingAs($this->cashier)->postJson('/pos/store/open', $this->floatCount(hundreds: 1, remark: 'Kurang 100 ribu'))
            ->assertOk()->assertJsonPath('data.state', 'open')->assertJsonPath('data.has_shift', true);

        $this->actingAs($this->cashier)->postJson('/pos/store/open', $this->floatCount(hundreds: 2))->assertStatus(422);
    }

    public function test_bootstrap_memuat_shift_gate_untuk_tiga_kondisi(): void
    {
        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')
            ->assertOk()->assertJsonPath('shift_gate.state', 'not_opened')->assertJsonPath('shift_gate.count_by_denomination', true)
            ->assertJsonPath('shift_gate.denominations', [100000, 50000])->assertJsonPath('shift', null);

        $this->actingAs($this->cashier)->postJson('/pos/store/open', $this->floatCount())->assertOk();
        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')
            ->assertOk()->assertJsonPath('shift_gate.state', 'open')->assertJsonPath('shift_gate.has_shift', true)
            ->assertJsonPath('shift.opened_store', true);

        StoreClosure::firstOrFail()->update(['closed_at' => now()]);
        $this->actingAs($this->cashier)->getJson('/pos/bootstrap')->assertOk()->assertJsonPath('shift_gate.state', 'eod_done');
    }

    public function test_logout_mengakhiri_shift_dan_toko_tetap_open(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/store/open', $this->floatCount())->assertOk();

        $this->actingAs($this->cashier)->post('/logout')->assertRedirect();

        $this->assertSame('logout', CashierShift::firstOrFail()->ended_reason);
        $this->assertTrue(StoreClosure::isOpen($this->store->id, company_today()));
    }

    public function test_akhiri_shift_lewat_endpoint(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/store/open', $this->floatCount())->assertOk();

        $this->actingAs($this->cashier)->postJson('/pos/shift/end')->assertOk();

        $this->assertFalse(ShiftService::hasActiveShift($this->cashier, $this->store->id));
    }

    public function test_status_toko_memuat_opened_dan_jumlah_shift_aktif(): void
    {
        $status = store_business_status($this->store->id);
        $this->assertFalse($status['opened']);
        $this->assertSame(0, $status['active_shifts']);

        ShiftService::openStore($this->store, $this->cashier, $this->floatCount());

        $status = store_business_status($this->store->id);
        $this->assertTrue($status['opened']);
        $this->assertSame(1, $status['active_shifts']);
    }

    public function test_status_bar_admin_menampilkan_status_toko(): void
    {
        $this->actingAs($this->owner)->withSession(['active_store_id' => $this->store->id])
            ->get(route('admin.dashboard'))->assertOk()->assertSee('has not been opened');

        ShiftService::openStore($this->store, $this->cashier, $this->floatCount());

        $this->actingAs($this->owner)->withSession(['active_store_id' => $this->store->id])
            ->get(route('admin.dashboard'))->assertOk()->assertSee('1 shift(s) active');
    }

    public function test_toko_yang_tidak_pernah_dibuka_tidak_terkunci_menunggu_eod(): void
    {
        $this->assertNull(StoreClosure::unclosedPriorDate($this->store->id, company_today()));
        $this->assertFalse(store_business_status($this->store->id)['opened']);
        $this->assertNull(store_business_status($this->store->id)['locked_date']);
    }

    public function test_is_open_benar_hanya_untuk_toko_yang_dibuka_dan_belum_eod(): void
    {
        $date = company_today();
        $this->assertFalse(StoreClosure::isOpen($this->store->id, $date));

        $closure = StoreClosure::create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'business_date' => $date, 'opened_at' => now(), 'opened_by' => $this->cashier->id]);
        $this->assertTrue(StoreClosure::isOpen($this->store->id, $date));

        $closure->update(['closed_at' => now()]);
        $this->assertFalse(StoreClosure::isOpen($this->store->id, $date));
    }
}
