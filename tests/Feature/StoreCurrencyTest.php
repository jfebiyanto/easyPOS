<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Test',
            'username' => 'admintest',
            'email' => 'admin@test.local',
            'type' => 'admin',
            'is_superuser' => true,
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
        Currency::create(['code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);
        Currency::create(['code' => 'USD', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true]);
        Currency::create(['code' => 'SGD', 'symbol' => 'S$', 'decimal_places' => 2, 'is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.stores.create'))
            ->assertOk()
            ->assertSee('IDR - Rp')
            ->assertSee('USD - $')
            ->assertDontSee('SGD - S$') // nonaktif tidak ditawarkan
            ->assertDontSee('IDR - Rupiah'); // hardcode lama sudah tidak dipakai
    }

    public function test_store_baru_wajib_memakai_mata_uang_terdaftar_dan_aktif(): void
    {
        Currency::create(['code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);
        Currency::create(['code' => 'JPY', 'symbol' => '¥', 'decimal_places' => 0, 'is_active' => false]);

        // Kode tidak terdaftar di modul Mata Uang → ditolak.
        $this->actingAs($this->admin)
            ->post(route('admin.stores.store'), $this->validPayload(['currency' => 'XXX']))
            ->assertSessionHasErrors('currency');

        // Mata uang nonaktif → ditolak.
        $this->post(route('admin.stores.store'), $this->validPayload(['currency' => 'JPY']))
            ->assertSessionHasErrors('currency');

        // Mata uang aktif → diterima.
        $this->post(route('admin.stores.store'), $this->validPayload())
            ->assertRedirect(route('admin.stores.index'));

        $this->assertDatabaseHas('stores', ['code' => 'GR02', 'currency' => 'IDR']);

        // Kode dikirim huruf kecil tetap diterima dan dinormalisasi huruf besar.
        $this->post(route('admin.stores.store'), $this->validPayload(['code' => 'GR03', 'currency' => 'idr']))
            ->assertRedirect(route('admin.stores.index'));

        $this->assertDatabaseHas('stores', ['code' => 'GR03', 'currency' => 'IDR']);
    }

    public function test_edit_gerai_pertahankan_mata_uang_lama_yang_belum_terdaftar(): void
    {
        $store = Store::create([
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'pic_name' => 'PIC Satu',
            'currency' => 'OLD',
        ]);

        // Belum ada mata uang terdaftar; form tetap menampilkan nilai lama.
        $this->actingAs($this->admin)
            ->get(route('admin.stores.edit', $store))
            ->assertOk()
            ->assertSee('OLD (belum terdaftar di modul Mata Uang)');

        // Simpan tanpa mengubah mata uang → tetap diterima (data lama).
        $this->put(route('admin.stores.update', $store), $this->validPayload([
            'code' => 'GR01',
            'name' => 'Gerai Satu Baru',
            'currency' => 'OLD',
        ]))->assertRedirect(route('admin.stores.index'));

        $this->assertSame('OLD', $store->refresh()->currency);
    }

    public function test_mata_uang_gerai_yang_nonaktif_tetap_tampil_saat_edit(): void
    {
        Currency::create(['code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);

        $store = Store::create([
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
        Currency::create(['code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);
        Currency::create(['code' => 'USD', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true]);

        $store = Store::create([
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
        Currency::create(['code' => 'IDR', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);

        $store = Store::create([
            'code' => 'GR01',
            'name' => 'Gerai Satu',
            'pic_name' => 'PIC Satu',
            'currency' => 'IDR',
        ]);

        $this->assertTrue($store->currencyRecord()->exists());
        $this->assertSame('Rp', $store->currencyRecord()->first()->symbol);
        // Atribut `currency` tetap berisi kode mata uang.
        $this->assertSame('IDR', $store->currency);
    }
}
