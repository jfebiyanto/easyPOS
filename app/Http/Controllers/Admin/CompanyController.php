<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\TaxGroup;
use App\Support\ActivityLogger;
use App\Support\Sap\SapServiceLayer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CompanyController extends Controller
{
    use AuthorizesSuperuser;

    public function edit(): View
    {
        return view('admin.company.form', [
            'company' => CompanyDetail::current(),
            'taxGroups' => TaxGroup::where('company_id', current_company_id())
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', CompanyDetail::current()->tax_group_id))
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function update(Request $request)
    {
        $this->ensureSuperuser($request);

        $company = CompanyDetail::current();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'npwp_number' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'ppn_enabled' => ['sometimes', 'boolean'],
            'tax_group_id' => [
                'nullable', 'required_if_accepted:ppn_enabled', 'string',
                Rule::exists('tax_groups', 'id')->where('company_id', current_company_id())->where('is_active', 1),
            ],
            'ppn_inclusive' => ['sometimes', 'boolean'],
            'base_currency_code' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:40'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'return_window_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'return_require_receipt' => ['sometimes', 'boolean'],
        ]);

        $data['ppn_enabled'] = $request->boolean('ppn_enabled');
        $data['ppn_inclusive'] = $request->boolean('ppn_inclusive');
        // Tarif PPN selalu mengikuti Tax Group terpilih (0% tetap PPN 0%, bukan PPN nonaktif).
        $data['ppn_rate'] = ($data['tax_group_id'] ?? null) ? (float) TaxGroup::whereKey($data['tax_group_id'])->value('rate') : 0;

        $data['allow_negative_stock'] = $request->boolean('allow_negative_stock');
        $data['return_require_receipt'] = $request->boolean('return_require_receipt');

        $company->update($data);

        ActivityLogger::log('company.updated', "Mengubah konfigurasi perusahaan: {$company->name}", $company);

        return redirect()->route('admin.company.edit')
            ->with('success', 'Konfigurasi perusahaan berhasil disimpan.');
    }

    /**
     * Simpan konfigurasi integrasi SAP B1 (form terpisah dari identitas perusahaan).
     *
     * Sebelum impor awal semua parameter bebas diubah. Setelah impor awal database
     * terkunci, perubahan lain wajib dikonfirmasi password akun easyPOS, dan
     * koneksi yang akan aktif harus lolos tes login SAP.
     */
    public function updateSap(Request $request): RedirectResponse
    {
        $this->ensureSuperuser($request);

        $company = CompanyDetail::current();

        $data = $request->validate([
            'sap_enabled' => ['sometimes', 'boolean'],
            'sap_allow_self_signed' => ['sometimes', 'boolean'],
            'sap_endpoint_url' => ['nullable', 'url', 'max:255'],
            'sap_db_name' => ['nullable', 'string', 'max:60'],
            'sap_username' => ['nullable', 'string', 'max:60'],
            'sap_password' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = [
            // Toggle integrasi: OFF = aplikasi berjalan standalone.
            'sap_enabled' => $request->boolean('sap_enabled'),
            // UX dibalik agar intuitif: dicentang = izinkan self-signed = verify OFF.
            'sap_verify_ssl' => ! $request->boolean('sap_allow_self_signed'),
            'sap_endpoint_url' => $data['sap_endpoint_url'] ?? null,
            'sap_db_name' => $data['sap_db_name'] ?? null,
            'sap_username' => $data['sap_username'] ?? null,
        ];

        // Password SAP hanya di-update jika diisi; kosong = tetap.
        if (filled($data['sap_password'] ?? null)) {
            $settings['sap_password'] = $data['sap_password'];
        }

        if (! $company->sapConnectionLocked()) {
            $company->update($settings);

            ActivityLogger::log('company.sap_updated', 'Mengubah konfigurasi integrasi SAP B1', $company, [
                'sap_enabled' => $company->sap_enabled,
                'sap_integration_active' => $company->sapIntegrationActive(),
            ]);

            return redirect()->route('admin.company.edit')
                ->with('success', 'Konfigurasi SAP B1 berhasil disimpan.');
        }

        // Data hasil impor terikat ke database ini; pindah database = reimplementasi easyPOS.
        if ($request->has('sap_db_name') && $settings['sap_db_name'] !== $company->sap_db_name) {
            throw ValidationException::withMessages([
                'sap_db_name' => 'Database SAP B1 tidak dapat diubah setelah impor data awal.',
            ]);
        }
        unset($settings['sap_db_name']);

        $changedFields = array_keys(array_filter(
            $settings,
            fn ($value, string $field): bool => $company->{$field} !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changedFields === []) {
            return redirect()->route('admin.company.edit')
                ->with('success', 'Tidak ada perubahan pada konfigurasi SAP B1.');
        }

        $this->confirmAccountPassword($request);

        if ($settings['sap_enabled']) {
            $this->ensureSapLoginSucceeds(
                (string) $settings['sap_endpoint_url'],
                (string) $company->sap_db_name,
                (string) $settings['sap_username'],
                (string) ($settings['sap_password'] ?? $company->sap_password),
                $settings['sap_verify_ssl'],
            );
        }

        $wasEnabled = $company->sap_enabled;
        $company->update(array_intersect_key($settings, array_flip($changedFields)));

        ActivityLogger::log('company.sap_updated', 'Mengubah konfigurasi integrasi SAP B1 setelah impor awal', $company, [
            'changed_fields' => $changedFields,
            'sap_enabled' => $company->sap_enabled,
        ]);

        $message = match (true) {
            $wasEnabled && ! $company->sap_enabled => 'Integrasi SAP B1 dinonaktifkan.',
            ! $wasEnabled && $company->sap_enabled => 'Integrasi SAP B1 diaktifkan kembali. Stok akan ditimpa saldo SAP B1 pada sinkronisasi berikutnya.',
            default => 'Konfigurasi SAP B1 berhasil disimpan.',
        };

        return redirect()->route('admin.company.edit')->with('success', $message);
    }

    /**
     * Kirim test koneksi ke SAP B1 (POST {CompanyDB, UserName, Password}).
     */
    public function testSap(Request $request): JsonResponse
    {
        $this->ensureSuperuser($request);

        $company = CompanyDetail::current();

        // Pakai nilai dari form (belum perlu disimpan); fallback ke data tersimpan.
        // Password kosong = pakai password tersimpan.
        $endpoint = trim((string) $request->input('sap_endpoint_url', $company->sap_endpoint_url));
        $dbName = trim((string) $request->input('sap_db_name', $company->sap_db_name));
        $username = trim((string) $request->input('sap_username', $company->sap_username));
        $password = filled($request->input('sap_password')) ? (string) $request->input('sap_password') : (string) $company->sap_password;
        $verifySsl = $request->has('sap_allow_self_signed')
            ? ! $request->boolean('sap_allow_self_signed')
            : (bool) $company->sap_verify_ssl;

        if ($endpoint === '' || $dbName === '' || $username === '' || $password === '') {
            return response()->json([
                'success' => false,
                'message' => 'Konfigurasi SAP B1 belum lengkap. Lengkapi Endpoint URL, Database, Username, dan Password.',
            ], 422);
        }

        $payload = [
            'CompanyDB' => $dbName,
            'UserName' => $username,
            'Password' => $password,
        ];

        // SAP B1 Service Layer: URL tersimpan adalah base (mis. .../b1s/v1).
        // Resource login ada di .../b1s/v1/Login — tambahkan bila belum ada.
        $url = rtrim($endpoint, '/');
        if (! Str::endsWith(strtolower($url), '/login')) {
            $url .= '/Login';
        }

        SapServiceLayer::assertSafeUrl($url);

        return response()->json($this->attemptSapLogin($url, $payload, $verifySsl));
    }

    /**
     * Konfirmasi ulang password akun easyPOS untuk setiap simpan; salah password dibatasi 5x per menit.
     */
    private function confirmAccountPassword(Request $request): void
    {
        $limiterKey = 'sap-config-confirm:'.$request->user()->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            throw ValidationException::withMessages([
                'current_password' => 'Terlalu banyak percobaan password. Coba lagi dalam '.RateLimiter::availableIn($limiterKey).' detik.',
            ]);
        }

        if (! Hash::check((string) $request->input('current_password'), $request->user()->password)) {
            RateLimiter::hit($limiterKey, 60);

            throw ValidationException::withMessages([
                'current_password' => 'Password akun easyPOS salah.',
            ]);
        }

        RateLimiter::clear($limiterKey);
    }

    /**
     * Ulangi tes login SAP di server sebelum menyimpan koneksi yang akan aktif.
     */
    private function ensureSapLoginSucceeds(string $endpoint, string $dbName, string $username, string $password, bool $verifySsl): void
    {
        if ($endpoint === '' || $dbName === '' || $username === '' || $password === '') {
            throw ValidationException::withMessages([
                'sap_connection' => 'Konfigurasi SAP B1 belum lengkap. Lengkapi Endpoint URL, Username, dan Password.',
            ]);
        }

        $url = rtrim($endpoint, '/');
        if (! Str::endsWith(strtolower($url), '/login')) {
            $url .= '/Login';
        }

        try {
            SapServiceLayer::assertSafeUrl($url);
        } catch (HttpException $e) {
            throw ValidationException::withMessages(['sap_connection' => $e->getMessage()]);
        }

        $result = $this->attemptSapLogin($url, [
            'CompanyDB' => $dbName,
            'UserName' => $username,
            'Password' => $password,
        ], $verifySsl);

        if (! $result['success']) {
            throw ValidationException::withMessages(['sap_connection' => $result['message']]);
        }
    }

    /**
     * @param  array{CompanyDB: string, UserName: string, Password: string}  $payload
     * @return array{success: bool, message: string, http_status?: int}
     */
    private function attemptSapLogin(string $url, array $payload, bool $verifySsl): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withOptions(['verify' => $verifySsl])
                ->post($url, $payload);

            $status = $response->status();
            $success = $status >= 200 && $status < 300;

            return [
                'success' => $success,
                'http_status' => $status,
                'message' => $success
                    ? "Koneksi ke SAP B1 berhasil (HTTP {$status})."
                    : "SAP B1 merespons, tetapi login gagal (HTTP {$status}). Periksa kredensial atau URL.",
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => 'Tidak dapat terhubung ke SAP B1: '.Str::limit($e->getMessage(), 180),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Gagal menguji koneksi: '.Str::limit($e->getMessage(), 180),
            ];
        }
    }
}
