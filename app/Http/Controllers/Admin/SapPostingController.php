<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Support\Sap\SapReturnPosting;
use App\Support\Sap\SapSalePosting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Integration Monitor: status posting penjualan POS, retur, dan dokumen inventori ke SAP B1, serta kirim ulang yang gagal.
 */
class SapPostingController extends Controller
{
    use AuthorizesSuperuser;

    public function index(Request $request): View|RedirectResponse
    {
        $company = CompanyDetail::current();

        if (! $company->sapIntegrationActive()) {
            return redirect()->route('admin.company.edit')
                ->with('error', 'Enable and complete the SAP B1 integration configuration first.');
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
                ->selectRaw('sap_post_status, count(*) as jumlah')
                ->groupBy('sap_post_status')
                ->pluck('jumlah', 'sap_post_status'),
        ]);
    }

    public function retry(Request $request, Sale $sale): RedirectResponse
    {
        $this->ensureSuperuser($request);
        abort_unless($sale->company_id === current_company_id(), 404);
        abort_if($sale->sap_post_status === Sale::SAP_POSTED, 422, 'The sale has already been posted to SAP B1.');

        SapSalePosting::queue($sale);

        return back()->with('success', "Sale {$sale->number} scheduled to be reposted to SAP B1.");
    }

    public function returns(Request $request): View|RedirectResponse
    {
        $company = CompanyDetail::current();

        if (! $company->sapIntegrationActive()) {
            return redirect()->route('admin.company.edit')
                ->with('error', 'Enable and complete the SAP B1 integration configuration first.');
        }

        $status = $request->query('status');
        $statuses = [SalesReturn::SAP_PENDING, SalesReturn::SAP_CREDITED, SalesReturn::SAP_POSTED, SalesReturn::SAP_FAILED];

        return view('admin.sap-postings.returns', [
            'returns' => SalesReturn::where('company_id', $company->id)
                ->whereNotNull('sap_post_status')
                ->when(in_array($status, $statuses, true), fn ($q) => $q->where('sap_post_status', $status))
                ->with(['store', 'originalSale'])
                ->latest('created_at')
                ->paginate(20)
                ->withQueryString(),
            'status' => $status,
            'counts' => SalesReturn::where('company_id', $company->id)
                ->whereNotNull('sap_post_status')
                ->selectRaw('sap_post_status, count(*) as jumlah')
                ->groupBy('sap_post_status')
                ->pluck('jumlah', 'sap_post_status'),
        ]);
    }

    /**
     * Log posting dokumen inventori ke SAP B1: Stock Transfer (Shipment, Receipt, Return) dan Penyesuaian Stok.
     */
    public function inventory(Request $request): View|RedirectResponse
    {
        $company = CompanyDetail::current();

        if (! $company->sapIntegrationActive()) {
            return redirect()->route('admin.company.edit')
                ->with('error', 'Enable and complete the SAP B1 integration configuration first.');
        }

        $nullDoc = 'cast(null as varchar(20))';
        $documents = DB::table('stock_transfer_shipments')
            ->selectRaw("id, 'shipment' as kind, number, posted_at as event_at, from_store_id as store_id, sap_post_status, sap_post_error, sap_doc_num as doc_a, {$nullDoc} as doc_b")
            ->where('company_id', $company->id)->whereNotNull('sap_post_status')
            ->unionAll(DB::table('stock_transfer_receipts')
                ->selectRaw("id, 'receipt' as kind, number, received_at as event_at, store_id, sap_post_status, sap_post_error, sap_doc_num as doc_a, {$nullDoc} as doc_b")
                ->where('company_id', $company->id)->whereNotNull('sap_post_status'))
            ->unionAll(DB::table('stock_transfer_returns')
                ->selectRaw("id, 'return' as kind, number, returned_at as event_at, store_id, sap_post_status, sap_post_error, sap_doc_num as doc_a, {$nullDoc} as doc_b")
                ->where('company_id', $company->id)->whereNotNull('sap_post_status'))
            ->unionAll(DB::table('stock_adjustments')
                ->selectRaw('id, \'adjustment\' as kind, number, posted_at as event_at, store_id, sap_post_status, sap_post_error, sap_receipt_doc_num as doc_a, sap_issue_doc_num as doc_b')
                ->where('company_id', $company->id)->whereNotNull('sap_post_status'));

        $status = $request->query('status');
        $statuses = [StockAdjustment::SAP_PENDING, StockAdjustment::SAP_POSTED, StockAdjustment::SAP_FAILED];

        $rows = DB::query()->fromSub($documents, 'docs')
            ->when(in_array($status, $statuses, true), fn ($q) => $q->where('sap_post_status', $status))
            ->orderByDesc('event_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.sap-postings.inventory', [
            'rows' => $rows,
            'stores' => Store::whereIn('id', $rows->pluck('store_id')->unique())->pluck('code', 'id'),
            'status' => $status,
            'counts' => DB::query()->fromSub($documents, 'docs')
                ->selectRaw('sap_post_status, count(*) as jumlah')
                ->groupBy('sap_post_status')
                ->pluck('jumlah', 'sap_post_status'),
        ]);
    }

    public function retryReturn(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        $this->ensureSuperuser($request);
        abort_unless($salesReturn->company_id === current_company_id(), 404);
        abort_if($salesReturn->sap_post_status === SalesReturn::SAP_POSTED, 422, 'The return has already been posted to SAP B1.');

        SapReturnPosting::queue($salesReturn);

        return back()->with('success', "Return {$salesReturn->number} scheduled to be reposted to SAP B1.");
    }
}
