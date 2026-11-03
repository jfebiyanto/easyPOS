<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\SapSyncRun;
use App\Support\ActivityLogger;
use App\Support\Sap\SapSyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Impor data awal dari SAP B1 + pengaturan interval sinkronisasi berkala.
 * Hanya tersedia bila integrasi SAP B1 aktif dan konfigurasinya lengkap.
 */
class SapImportController extends Controller
{
    use AuthorizesSuperuser;

    public function index(): View|RedirectResponse
    {
        $company = CompanyDetail::current();

        if (! $company->sapIntegrationActive()) {
            return redirect()->route('admin.company.edit')
                ->with('error', 'Aktifkan dan lengkapi konfigurasi integrasi SAP B1 terlebih dahulu.');
        }

        return view('admin.sap-import.index', [
            'company' => $company,
            'runs' => SapSyncRun::where('company_id', $company->id)->latest()->limit(10)->get(),
            'activeRun' => SapSyncDispatcher::activeRun($company),
        ]);
    }

    /**
     * Jalankan impor sekarang. `initial` = tarik semua data, `delta` = hanya yang berubah.
     */
    public function run(Request $request): RedirectResponse
    {
        $this->ensureSuperuser($request);

        $data = $request->validate(['mode' => ['required', 'in:initial,delta']]);

        $company = CompanyDetail::current();
        abort_unless($company->sapIntegrationActive(), 422, 'Integrasi SAP B1 belum aktif.');

        $mode = $data['mode'];

        // Impor awal tidak mencocokkan data, jadi hanya boleh sekali. Berikutnya memakai mode perubahan.
        if ($mode === SapSyncRun::MODE_INITIAL && $company->sap_initial_imported_at) {
            return back()->with('error', 'Impor data awal sudah pernah dilakukan. Gunakan "Sinkronkan Perubahan Sekarang".');
        }

        if ($mode === SapSyncRun::MODE_DELTA && ! $company->sap_initial_imported_at) {
            $mode = SapSyncRun::MODE_INITIAL;
        }

        $run = SapSyncDispatcher::start($company, $mode, 'manual');

        if (! $run) {
            return back()->with('error', 'Impor SAP B1 sebelumnya masih berjalan.');
        }

        ActivityLogger::log('sap.import.started', "Memulai impor SAP B1 ({$mode})", $company, ['run_id' => $run->id]);

        return back()->with('success', 'Impor dari SAP B1 dimulai. Proses berjalan di latar belakang.');
    }

    /**
     * Hentikan impor yang sedang antre/berjalan agar bisa memulai yang baru.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $this->ensureSuperuser($request);

        $company = CompanyDetail::current();
        $run = SapSyncDispatcher::cancel($company);

        if (! $run) {
            return back()->with('error', 'Tidak ada impor yang sedang berjalan.');
        }

        ActivityLogger::log('sap.import.cancelled', 'Membatalkan impor SAP B1', $company, ['run_id' => $run->id]);

        return back()->with('success', 'Impor SAP B1 dibatalkan. Anda dapat memulai impor baru.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->ensureSuperuser($request);

        $data = $request->validate([
            'sap_sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'sap_auto_sync' => ['sometimes', 'boolean'],
        ]);

        $company = CompanyDetail::current();
        $company->update([
            'sap_sync_interval_minutes' => $data['sap_sync_interval_minutes'],
            'sap_auto_sync' => $request->boolean('sap_auto_sync'),
        ]);

        ActivityLogger::log('sap.import.settings', "Mengubah interval sinkronisasi SAP B1: {$company->sap_sync_interval_minutes} menit", $company);

        return back()->with('success', 'Pengaturan sinkronisasi disimpan.');
    }

    /**
     * Status run terbaru (dipolling oleh UI).
     */
    public function status(): JsonResponse
    {
        $company = CompanyDetail::current();
        $run = SapSyncRun::where('company_id', $company->id)->latest()->first();

        return response()->json([
            'in_progress' => $run?->isInProgress() ?? false,
            'status' => $run?->status,
            'summary' => $run?->summary,
            'error' => $run?->error,
        ]);
    }
}
