<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Currency;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);

        $this->admin = User::create([
            'company_id' => $this->company->id,
            'name' => 'Admin Test',
            'username' => 'admintest',
            'email' => 'admin@test.local',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'GR02',
            'name' => 'Gerai Dua',
            'pic_name' => 'PIC Dua',
            'currency' => 'IDR',
        ], $overrides);
    }

    public function test_form_gerai_menampilkan_mata_uang_dari_modul_mata_uang(): void
    {
        Currency::create(['company_id' => $this->company->id, 'code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);
        Currency::create(['company_id' => $this->company->id, 'code' => 'USD', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true]);
        Currency::create(['company_id' => $this->company->id, 'code' => 'SGD', 'symbol' => 'S$', 'decimal_places' => 2, 'is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.stores.create'))
            ->assertOk()
            ->assertSee('IDR - Rp')
            ->assertSee('USD - $')
            ->assertDontSee('SGD - S$');
    }

    public function test_store_baru_wajib_memakai_mata_uang_terdaftar_dan_aktif(): void
    {
        Currency::create(['company_id' => $this->company->id, 'code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);
        Currency::create(['company_id' => $this->company->id, 'code' => 'JPY', 'symbol' => '¥', 'decimal_places' => 0, 'is_active' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.stores.store'), $this->validPayload(['currency' => 'XXX']))
            ->assertSessionHasErrors('currency');

        $this->post(route('admin.stores.store'), $this->validPayload(['currency' => 'JPY']))
            ->assertSessionHasErrors('currency');

        $this->post(route('admin.stores.store'), $this->validPayload())
            ->assertRedirect(route('admin.stores.index'));

        $this->assertDatabaseHas('stores', ['code' => 'GR02', 'currency' => 'IDR']);

        $this->post(route('admin.stores.store'), $this->validPayload(['code' => 'GR03', 'currency' => 'idr']))
            ->assertRedirect(route('admin.stores.index'));

        $this->assertDatabaseHas('stores', ['code' => 'GR03', 'currency' => 'IDR']);
    }

    public function test_edit_gerai_pertahankan_mata_uang_lama_yang_belum_terdaftar(): void
    {
        $store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'pic_name' => 'PIC Satu',
            'currency' => 'OLD',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.stores.edit', $store))
            ->assertOk()
            ->assertSee('OLD (belum terdaftar di modul Mata Uang)');

        $this->put(route('admin.stores.update', $store), $this->validPayload([
            'code' => 'GR01',
            'name' => 'Gerai Satu Baru',
            'currency' => 'OLD',
        ]))->assertRedirect(route('admin.stores.index'));

        $this->assertSame('OLD', $store->refresh()->currency);
    }

    public function test_mata_uang_gerai_yang_nonaktif_tetap_tampil_saat_edit(): void
    {
        Currency::create(['company_id' => $this->company->id, 'code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);

        $store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'pic_name' => 'PIC Satu',
            'currency' => 'IDR',
        ]);

        Currency::where('code', 'IDR')->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.stores.edit', $store))
            ->assertOk()
            ->assertSee('IDR - Rp (nonaktif)');
    }

    public function test_edit_gerai_bisa_berpindah_ke_mata_uang_terdaftar(): void
    {
        Currency::create(['company_id' => $this->company->id, 'code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);
        Currency::create(['company_id' => $this->company->id, 'code' => 'USD', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true]);

        $store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'pic_name' => 'PIC Satu',
            'currency' => 'IDR',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.stores.update', $store), $this->validPayload([
                'code' => 'GR01',
                'currency' => 'USD',
            ]))->assertRedirect(route('admin.stores.index'));

        $this->assertSame('USD', $store->refresh()->currency);
    }

    public function test_relasi_currency_menghubungkan_gerai_ke_modul_mata_uang(): void
    {
        Currency::create(['company_id' => $this->company->id, 'code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);

        $store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'pic_name' => 'PIC Satu',
            'currency' => 'IDR',
        ]);

        $this->assertTrue($store->currencyRecord()->exists());
        $this->assertSame('Rp', $store->currencyRecord()->first()->symbol);
        $this->assertSame('IDR', $store->currency);
    }
}
