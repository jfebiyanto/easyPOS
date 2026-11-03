<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * Saat `npm run dev` (file hot aktif) halaman POS harus bisa memuat React dari Vite dev server.
 */
class ViteDevModeTest extends TestCase
{
    use RefreshDatabase;

    private string $hotFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotFile = tempnam(sys_get_temp_dir(), 'vite-hot');
        file_put_contents($this->hotFile, 'http://[::1]:5173');
    }

    protected function tearDown(): void
    {
        @unlink($this->hotFile);

        parent::tearDown();
    }

    public function test_csp_tetap_dikirim_tanpa_vite_dev_server(): void
    {
        $this->app['env'] = 'local';
        Vite::useHotFile(sys_get_temp_dir().'/tidak-ada-file-hot');

        $this->get('/login')->assertHeader('Content-Security-Policy');
    }

    public function test_csp_tetap_dikirim_di_luar_local_meski_vite_dev_server_aktif(): void
    {
        $this->app['env'] = 'production';
        Vite::useHotFile($this->hotFile);

        $this->get('/login')->assertHeader('Content-Security-Policy');
    }

    public function test_csp_dilewati_di_local_saat_vite_dev_server_aktif(): void
    {
        $this->app['env'] = 'local';
        Vite::useHotFile($this->hotFile);

        $this->get('/login')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_halaman_pos_memuat_preamble_react_refresh_saat_dev(): void
    {
        Vite::useHotFile($this->hotFile);

        $company = CompanyDetail::create(['name' => 'Test Co']);
        $store = Store::create(['company_id' => $company->id, 'code' => 'STR001', 'name' => 'Gerai A', 'pic_name' => 'PIC', 'currency' => 'IDR']);
        $cashier = User::create([
            'company_id' => $company->id,
            'store_id' => $store->id,
            'name' => 'Kasir',
            'username' => 'kasir1',
            'role' => User::ROLE_CASHIER,
            'is_active' => true,
            'password' => 'password',
        ]);

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertOk()
            ->assertSee('__vite_plugin_react_preamble_installed__', false);
    }
}
