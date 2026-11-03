<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Support\ActivityLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CompanyController extends Controller
{
    use AuthorizesSuperuser;

    public function edit(): View
    {
        return view('admin.company.form', [
            'company' => CompanyDetail::current(),
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
            'ppn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ppn_inclusive' => ['sometimes', 'boolean'],
            'base_currency_code' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:40'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'return_window_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'return_require_receipt' => ['sometimes', 'boolean'],
            'sap_enabled' => ['sometimes', 'boolean'],
            'sap_allow_self_signed' => ['sometimes', 'boolean'],
            'sap_endpoint_url' => ['nullable', 'url', 'max:255'],
            'sap_db_name' => ['nullable', 'string', 'max:60'],
            'sap_username' => ['nullable', 'string', 'max:60'],
            'sap_password' => ['nullable', 'string', 'max:255'],
        ]);

        $data['ppn_enabled'] = $request->boolean('ppn_enabled');
        $data['ppn_inclusive'] = $request->boolean('ppn_inclusive');
        $data['ppn_rate'] = $data['ppn_enabled'] ? ($data['ppn_rate'] ?? 11) : 0;

        $data['allow_negative_stock'] = $request->boolean('allow_negative_stock');
        $data['return_require_receipt'] = $request->boolean('return_require_receipt');

        // Toggle integrasi: OFF = aplikasi berjalan standalone.
        $data['sap_enabled'] = $request->boolean('sap_enabled');
        // UX dibalik agar intuitif: dicentang = izinkan self-signed = verify OFF.
        $data['sap_verify_ssl'] = ! $request->boolean('sap_allow_self_signed');

        // Password SAP hanya di-update jika diisi; kosong = tetap.
        if (blank($data['sap_password'] ?? null)) {
            unset($data['sap_password']);
        }

        $company->update($data);

        ActivityLogger::log('company.updated', "Mengubah konfigurasi perusahaan: {$company->name}", $company, [
            'sap_enabled' => $company->sap_enabled,
            'sap_integration_active' => $company->sapIntegrationActive(),
        ]);

        return redirect()->route('admin.company.edit')
            ->with('success', 'Konfigurasi perusahaan berhasil disimpan.');
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

        $this->assertSafeSapUrl($url);

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withOptions(['verify' => $verifySsl])
                ->post($url, $payload);

            $status = $response->status();
            $success = $status >= 200 && $status < 300;

            return response()->json([
                'success' => $success,
                'http_status' => $status,
                'message' => $success
                    ? "Koneksi ke SAP B1 berhasil (HTTP {$status})."
                    : "SAP B1 merespons, tetapi login gagal (HTTP {$status}). Periksa kredensial atau URL.",
            ]);
        } catch (ConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat terhubung ke SAP B1: '.Str::limit($e->getMessage(), 180),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menguji koneksi: '.Str::limit($e->getMessage(), 180),
            ]);
        }
    }

    /**
     * Cegah SSRF: endpoint SAP tidak boleh menunjuk ke alamat internal/privat
     * (loopback, RFC1918, link-local/metadata, dst).
     */
    private function assertSafeSapUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        abort_if(! in_array($scheme, ['http', 'https'], true) || $host === '', 422, 'URL endpoint SAP tidak valid.');

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : (gethostbynamel($host) ?: []);

        abort_if($ips === [], 422, 'Host endpoint SAP tidak dapat di-resolve.');

        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            abort_if($public === false, 422, 'Endpoint SAP tidak boleh mengarah ke alamat internal/privat.');
        }
    }
}
