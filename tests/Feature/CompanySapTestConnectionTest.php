<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompanySapTestConnectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $company = CompanyDetail::create(['name' => 'Test Co']);
        $store = Store::create([
            'company_id' => $company->id,
            'code' => 'STR001',
            'name' => 'Store Satu',
            'pic_name' => 'PIC',
            'currency' => 'IDR',
        ]);

        $this->owner = User::create([
            'company_id' => $company->id,
            'store_id' => $store->id,
            'name' => 'Owner',
            'username' => 'owner1',
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    public function test_tes_koneksi_memakai_nilai_form_tanpa_perlu_disimpan(): void
    {
        Http::fake(['*' => Http::response(['SessionId' => 'abc'], 200)]);

        $this->actingAs($this->owner)
            ->postJson(route('admin.company.test-sap'), [
                'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
                'sap_db_name' => 'SBO_TEST',
                'sap_username' => 'manager',
                'sap_password' => 'secret',
                'sap_allow_self_signed' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('debug');

        Http::assertSent(fn ($request) => $request['CompanyDB'] === 'SBO_TEST'
            && $request['UserName'] === 'manager'
            && $request['Password'] === 'secret'
            && $request->url() === 'https://8.8.8.8:50000/b1s/v1/Login');

        $this->assertNull(CompanyDetail::current()->sap_endpoint_url);
    }

    public function test_tes_koneksi_gagal_jika_parameter_belum_lengkap(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('admin.company.test-sap'), [
                'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
                'sap_db_name' => 'SBO_TEST',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
