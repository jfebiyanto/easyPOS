<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Sale;
use App\Support\Sap\SapSalePosting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Status posting penjualan POS ke SAP B1 dan kirim ulang yang gagal.
 */
class SapPostingController extends Controller
{
    use AuthorizesSuperuser;

    public function index(Request $request): View|RedirectResponse
    {
        $company = CompanyDetail::current();

        if (! $company->sapIntegrationActive()) {
            return redirect()->route('admin.company.edit')
                ->with('error', 'Aktifkan dan lengkapi konfigurasi integrasi SAP B1 terlebih dahulu.');
        }

        $status = $request->query('status');

        return view('admin.sap-postings.index', [
            'sales' => Sale::where('company_id', $company->id)
                ->whereNotNull('sap_post_status')
                ->when(in_array($status, [Sale::SAP_PENDING, Sale::SAP_INVOICED, Sale::SAP_POSTED, Sale::SAP_FAILED], true), fn ($q) => $q->where('sap_post_status', $status))
                ->with(['store', 'customer'])
                ->latest('sold_at')
                ->paginate(20)
                ->withQueryString(),
            'status' => $status,
            'counts' => Sale::where('company_id', $company->id)
                ->whereNotNull('sap_post_status')
                ->selectRaw('sap_post_status, count(*) as total')
                ->groupBy('sap_post_status')
                ->pluck('total', 'sap_post_status'),
        ]);
    }

    public function retry(Request $request, Sale $sale): RedirectResponse
    {
        $this->ensureSuperuser($request);
        abort_unless($sale->company_id === current_company_id(), 404);
        abort_if($sale->sap_post_status === Sale::SAP_POSTED, 422, 'Penjualan sudah terposting ke SAP B1.');

        SapSalePosting::queue($sale);

        return back()->with('success', "Penjualan {$sale->number} dijadwalkan untuk diposting ulang ke SAP B1.");
    }
}
