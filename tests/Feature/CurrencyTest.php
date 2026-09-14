<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Currency;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);

        $this->store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'address' => 'Jl. Testing No. 1',
            'pic_name' => 'PIC Satu',
            'pic_phone' => '081200000001',
            'ppn_enabled' => false,
            'ppn_rate' => 11,
            'currency' => 'IDR',
        ]);

        $this->admin = User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Admin Test',
            'username' => 'admintest',
            'email' => 'admin@test.local',
            'phone' => '081200000000',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    private function makeCurrency(string $code, string $symbol = 'Rp', bool $active = true): Currency
    {
        return Currency::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'symbol' => $symbol,
            'has_rounding' => false,
            'decimal_places' => 2,
            'is_active' => $active,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'idr',
            'symbol' => 'Rp',
            'has_rounding' => '1',
            'decimal_places' => 2,
            'is_active' => '1',
        ], $overrides);
    }

    public function test_halaman_index_form_dan_pecahan_dapat_diakses(): void
    {
        $currency = $this->makeCurrency('IDR');

        $this->actingAs($this->admin);

        $this->get(route('admin.currencies.index'))->assertOk();
        $this->get(route('admin.currencies.create'))->assertOk();
        $this->get(route('admin.currencies.edit', $currency))->assertOk();
        $this->get(route('admin.currencies.show', $currency))->assertOk();
    }

    public function test_admin_dapat_menambahkan_mata_uang_dengan_kode_iso_huruf_besar(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.currencies.store'), $this->validPayload())
            ->assertRedirect(route('admin.currencies.index'));

        $currency = Currency::where('code', 'IDR')->first();
        $this->assertNotNull($currency);
        $this->assertSame('Rp', $currency->symbol);
        $this->assertTrue($currency->has_rounding);
        $this->assertSame(2, $currency->decimal_places);
        $this->assertTrue($currency->is_active);
    }

    public function test_kode_mata_uang_harus_unik(): void
    {
        $this->makeCurrency('IDR');

        $this->actingAs($this->admin)
            ->post(route('admin.currencies.store'), $this->validPayload(['code' => 'IDR']))
            ->assertSessionHasErrors('code');
    }

    public function test_validasi_kode_iso_tiga_huruf_dan_field_wajib(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.currencies.store'), [])
            ->assertSessionHasErrors(['code', 'symbol', 'decimal_places']);

        $this->post(route('admin.currencies.store'), $this->validPayload(['code' => 'IDRX']))
            ->assertSessionHasErrors('code');

        $this->post(route('admin.currencies.store'), $this->validPayload(['code' => '1DR']))
            ->assertSessionHasErrors('code');
    }

    public function test_admin_dapat_mengelola_nominal_pecahan(): void
    {
        $currency = $this->makeCurrency('IDR');

        $this->actingAs($this->admin)
            ->post(route('admin.currencies.denominations.store', $currency), ['value' => '100000', 'is_active' => '1'])
            ->assertRedirect(route('admin.currencies.show', $currency))
            ->assertSessionHas('success');

        $denomination = $currency->denominations()->where('value', '100000')->first();
        $this->assertNotNull($denomination);
        $this->assertTrue($denomination->is_active);

        $this->post(route('admin.currencies.denominations.store', $currency), ['value' => '100000'])
            ->assertSessionHasErrors('value');

        $usd = $this->makeCurrency('USD', '$');
        $this->post(route('admin.currencies.denominations.store', $usd), ['value' => '100000'])
            ->assertRedirect(route('admin.currencies.show', $usd));

        $this->delete(route('admin.currencies.denominations.destroy', [$currency, $denomination]))
            ->assertRedirect(route('admin.currencies.show', $currency));

        $this->assertDatabaseMissing('currency_denominations', ['id' => $denomination->id]);
        $this->assertSame(1, $usd->denominations()->count());
    }

    public function test_update_status_aktif_dan_digit_desimal(): void
    {
        $currency = $this->makeCurrency('USD', '$');

        $payload = $this->validPayload([
            'code' => 'USD',
            'symbol' => '$',
            'decimal_places' => 0,
        ]);
        unset($payload['is_active'], $payload['has_rounding']);

        $this->actingAs($this->admin)
            ->put(route('admin.currencies.update', $currency), $payload)
            ->assertRedirect(route('admin.currencies.index'));

        $currency->refresh();
        $this->assertSame(0, $currency->decimal_places);
        $this->assertFalse($currency->is_active);
        $this->assertFalse($currency->has_rounding);
    }

    public function test_hapus_mata_uang_menghapus_pecahannya(): void
    {
        $currency = $this->makeCurrency('SGD', 'S$');
        $currency->denominations()->create(['value' => 10, 'is_active' => true]);
        $currency->denominations()->create(['value' => 50, 'is_active' => true]);

        $this->actingAs($this->admin)
            ->delete(route('admin.currencies.destroy', $currency))
            ->assertRedirect(route('admin.currencies.index'));

        $this->assertDatabaseMissing('currencies', ['code' => 'SGD']);
        $this->assertDatabaseCount('currency_denominations', 0);
    }
}
