<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CompanyDetail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompanySapSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_URL = 'https://8.8.8.8:50000/b1s/v1/Login';

    private CompanyDetail $company;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyDetail::create(['name' => 'Test Co']);
        $this->store = Store::create([
            'company_id' => $this->company->id,
            'code' => 'STR001',
            'name' => 'Store Satu',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);

        $this->owner = $this->makeUser(User::ROLE_OWNER, 'owner1');
    }

    private function makeUser(string $role, string $username): User
    {
        return User::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => $username,
            'username' => $username,
            'role' => $role,
            'is_active' => true,
            'password' => 'rahasia-owner',
        ]);
    }

    private function lockAfterInitialImport(): void
    {
        $this->company->update([
            'sap_enabled' => true,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
            'sap_db_name' => 'SBO_LIVE',
            'sap_username' => 'manager',
            'sap_password' => 'sap-lama',
            'sap_verify_ssl' => false,
            'sap_initial_imported_at' => now()->subDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sapPayload(array $overrides = []): array
    {
        return array_merge([
            'sap_enabled' => 1,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
            'sap_db_name' => 'SBO_LIVE',
            'sap_username' => 'manager',
            'sap_password' => '',
            'sap_allow_self_signed' => 1,
        ], $overrides);
    }

    public function test_sebelum_impor_awal_konfigurasi_sap_tersimpan_tanpa_password(): void
    {
        $response = $this->actingAs($this->owner)->put(route('admin.company.update-sap'), $this->sapPayload([
            'sap_db_name' => 'SBO_TEST',
            'sap_password' => 'sap-baru',
        ]));

        $response->assertRedirect(route('admin.company.edit'));
        $response->assertSessionHas('success', 'Konfigurasi SAP B1 berhasil disimpan.');

        $company = $this->company->fresh();
        $this->assertTrue($company->sap_enabled);
        $this->assertSame('SBO_TEST', $company->sap_db_name);
        $this->assertSame('sap-baru', $company->sap_password);
    }

    public function test_simpan_identitas_perusahaan_tidak_mengubah_konfigurasi_sap(): void
    {
        $this->lockAfterInitialImport();

        $this->actingAs($this->owner)->put(route('admin.company.update'), [
            'name' => 'Nama Baru',
            'base_currency_code' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'sap_enabled' => 0,
            'sap_db_name' => 'SBO_LAIN',
        ])->assertRedirect(route('admin.company.edit'));

        $company = $this->company->fresh();
        $this->assertSame('Nama Baru', $company->name);
        $this->assertTrue($company->sap_enabled);
        $this->assertSame('SBO_LIVE', $company->sap_db_name);
    }

    public function test_halaman_menampilkan_koneksi_terkunci_setelah_impor_awal(): void
    {
        $this->lockAfterInitialImport();

        $this->actingAs($this->owner)
            ->get(route('admin.company.edit'))
            ->assertSee('Database SAP B1 tidak dapat diubah.')
            ->assertSee('Ubah Koneksi SAP')
            ->assertSee('Konfirmasi Perubahan SAP B1');
    }

    public function test_halaman_tidak_mengunci_koneksi_sebelum_impor_awal(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.company.edit'))
            ->assertSee('Simpan Integrasi SAP')
            ->assertDontSee('Ubah Koneksi SAP')
            ->assertDontSee('Konfirmasi Perubahan SAP B1');
    }

    public function test_store_manager_tidak_dapat_mengubah_konfigurasi_sap(): void
    {
        $manager = $this->makeUser(User::ROLE_STORE_MANAGER, 'manager1');

        $this->actingAs($manager)
            ->put(route('admin.company.update-sap'), $this->sapPayload())
            ->assertForbidden();

        $this->assertFalse($this->company->fresh()->sap_enabled);
    }

    public function test_setelah_impor_awal_database_sap_tidak_dapat_diubah(): void
    {
        $this->lockAfterInitialImport();

        $this->actingAs($this->owner)
            ->put(route('admin.company.update-sap'), $this->sapPayload([
                'sap_db_name' => 'SBO_LAIN',
                'current_password' => 'rahasia-owner',
            ]))
            ->assertSessionHasErrors(['sap_db_name' => 'Database SAP B1 tidak dapat diubah setelah impor data awal.']);

        $this->assertSame('SBO_LIVE', $this->company->fresh()->sap_db_name);
    }

    public function test_setelah_impor_awal_perubahan_ditolak_bila_password_salah(): void
    {
        Http::preventStrayRequests();
        $this->lockAfterInitialImport();

        $this->actingAs($this->owner)
            ->put(route('admin.company.update-sap'), $this->sapPayload([
                'sap_username' => 'posintegrasi',
                'current_password' => 'salah',
            ]))
            ->assertSessionHasErrors(['current_password' => 'Password akun easyPOS salah.']);

        $this->assertSame('manager', $this->company->fresh()->sap_username);
        Http::assertNothingSent();
    }

    public function test_setelah_impor_awal_perubahan_tersimpan_bila_password_benar_dan_login_sap_berhasil(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::LOGIN_URL => Http::response(['SessionId' => 'abc'], 200)]);
        $this->lockAfterInitialImport();

        $response = $this->actingAs($this->owner)->put(route('admin.company.update-sap'), $this->sapPayload([
            'sap_username' => 'posintegrasi',
            'sap_password' => 'sap-baru',
            'current_password' => 'rahasia-owner',
        ]));

        $response->assertRedirect(route('admin.company.edit'));
        $response->assertSessionHas('success', 'Konfigurasi SAP B1 berhasil disimpan.');

        $company = $this->company->fresh();
        $this->assertSame('posintegrasi', $company->sap_username);
        $this->assertSame('sap-baru', $company->sap_password);

        Http::assertSent(fn (HttpRequest $request): bool => $request['CompanyDB'] === 'SBO_LIVE'
            && $request['UserName'] === 'posintegrasi'
            && $request['Password'] === 'sap-baru');

        $log = ActivityLog::where('action', 'company.sap_updated')->sole();
        $this->assertSame(['sap_username', 'sap_password'], $log->properties['changed_fields']);
        $this->assertStringNotContainsString('sap-baru', json_encode($log->properties));
    }

    public function test_setelah_impor_awal_perubahan_ditolak_bila_login_sap_gagal(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::LOGIN_URL => Http::response(['error' => 'invalid'], 401)]);
        $this->lockAfterInitialImport();

        $this->actingAs($this->owner)
            ->put(route('admin.company.update-sap'), $this->sapPayload([
                'sap_password' => 'sap-salah',
                'current_password' => 'rahasia-owner',
            ]))
            ->assertSessionHasErrors(['sap_connection' => 'SAP B1 merespons, tetapi login gagal (HTTP 401). Periksa kredensial atau URL.']);

        $this->assertSame('sap-lama', $this->company->fresh()->sap_password);
    }

    public function test_setelah_impor_awal_integrasi_dapat_dimatikan_dengan_password_tanpa_tes_login(): void
    {
        Http::preventStrayRequests();
        $this->lockAfterInitialImport();

        $this->actingAs($this->owner)
            ->put(route('admin.company.update-sap'), $this->sapPayload([
                'sap_enabled' => 0,
                'current_password' => 'rahasia-owner',
            ]))
            ->assertSessionHas('success', 'Integrasi SAP B1 dinonaktifkan.');

        $company = $this->company->fresh();
        $this->assertFalse($company->sap_enabled);
        $this->assertSame('SBO_LIVE', $company->sap_db_name);
        Http::assertNothingSent();
    }

    public function test_setelah_impor_awal_menyalakan_kembali_integrasi_wajib_lolos_login_sap(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::LOGIN_URL => Http::response(['SessionId' => 'abc'], 200)]);
        $this->lockAfterInitialImport();
        $this->company->update(['sap_enabled' => false]);

        $this->actingAs($this->owner)
            ->put(route('admin.company.update-sap'), $this->sapPayload(['current_password' => 'rahasia-owner']))
            ->assertSessionHas('success', 'Integrasi SAP B1 diaktifkan kembali. Stok akan ditimpa saldo SAP B1 pada sinkronisasi berikutnya.');

        $this->assertTrue($this->company->fresh()->sap_enabled);
        Http::assertSentCount(1);
    }

    public function test_setelah_impor_awal_simpan_tanpa_perubahan_tidak_meminta_password(): void
    {
        Http::preventStrayRequests();
        $this->lockAfterInitialImport();

        $this->actingAs($this->owner)
            ->put(route('admin.company.update-sap'), $this->sapPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Tidak ada perubahan pada konfigurasi SAP B1.');

        $this->assertSame(0, ActivityLog::where('action', 'company.sap_updated')->count());
    }

    public function test_konfirmasi_password_diblokir_setelah_lima_kali_salah(): void
    {
        Http::preventStrayRequests();
        $this->lockAfterInitialImport();
        $this->actingAs($this->owner);
        $payload = $this->sapPayload(['sap_enabled' => 0]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->put(route('admin.company.update-sap'), [...$payload, 'current_password' => 'salah']);
        }

        $this->put(route('admin.company.update-sap'), [...$payload, 'current_password' => 'rahasia-owner'])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($this->company->fresh()->sap_enabled);
    }
}
