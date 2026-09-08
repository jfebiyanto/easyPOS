<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    use \App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;

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
            'sap_enabled' => ['sometimes', 'boolean'],
            'sap_allow_self_signed' => ['sometimes', 'boolean'],
            'sap_endpoint_url' => ['nullable', 'url', 'max:255'],
            'sap_db_name' => ['nullable', 'string', 'max:60'],
            'sap_username' => ['nullable', 'string', 'max:60'],
            'sap_password' => ['nullable', 'string', 'max:255'],
        ]);

        $data['ppn_enabled'] = $request->boolean('ppn_enabled');
        $data['ppn_rate'] = $data['ppn_enabled'] ? ($data['ppn_rate'] ?? 11) : 0;

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

        if (! $company->hasSapConfig()) {
            return response()->json([
                'success' => false,
                'message' => 'Konfigurasi SAP B1 belum lengkap. Lengkapi Endpoint URL, Database, Username, dan Password.',
            ], 422);
        }

        $payload = [
            'CompanyDB' => $company->sap_db_name,
            'UserName' => $company->sap_username,
            'Password' => $company->sap_password,
        ];

        // SAP B1 Service Layer: URL tersimpan adalah base (mis. .../b1s/v1).
        // Resource login ada di .../b1s/v1/Login — tambahkan bila belum ada.
        $url = rtrim($company->sap_endpoint_url, '/');
        if (! \Illuminate\Support\Str::endsWith(strtolower($url), '/login')) {
            $url .= '/Login';
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->acceptJson()
                ->withOptions(['verify' => (bool) $company->sap_verify_ssl])
                ->post($url, $payload);

            $status = $response->status();
            $success = $status >= 200 && $status < 300;

            return response()->json([
                'success' => $success,
                'http_status' => $status,
                'url' => $url,
                'message' => $success
                    ? "Koneksi ke SAP B1 berhasil (HTTP {$status})."
                    : "SAP B1 merespons, tetapi login gagal (HTTP {$status}). Periksa kredensial atau URL.",
                'request' => [
                    'url' => $url,
                    'body' => $payload,
                ],
                'response' => $response->json(),
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat terhubung ke SAP B1: '.\Illuminate\Support\Str::limit($e->getMessage(), 180),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menguji koneksi: '.\Illuminate\Support\Str::limit($e->getMessage(), 180),
            ]);
        }
    }
}