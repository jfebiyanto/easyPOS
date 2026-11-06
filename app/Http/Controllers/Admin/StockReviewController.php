<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Support\ActivityLogger;
use App\Support\SaleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockReviewController extends Controller
{
    use AuthorizesCompany, ScopesToStore;

    public function index(Request $request): View
    {
        $storeIds = $this->allowedStoreIds($request);

        return view('admin.stock-review.index', [
            'sales' => Sale::whereIn('store_id', $storeIds)
                ->where('stock_review_status', 'pending')
                ->with(['store', 'lines', 'cashier'])
                ->latest('created_at')
                ->paginate(20),
        ]);
    }

    public function resolve(Request $request, Sale $sale)
    {
        abort_unless(in_array($sale->store_id, $this->allowedStoreIds($request), true), 403);
        $this->ensureCompany($sale->company_id);
        abort_unless($sale->stock_review_status === 'pending', 422, 'This transaction is not in review status.');

        $data = $request->validate([
            'action' => ['required', Rule::in(['valid', 'void'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['action'] === 'void') {
            SaleService::void($sale, $request->user(), $data['reason'] ?? 'Review EOD');
        }

        $sale->update([
            'stock_review_status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        ActivityLogger::log('stock.review_resolved', "Resolved review of transaction {$sale->number} ({$data['action']})", $sale);

        return redirect()->route('admin.stock-review.index')->with('success', "Review {$sale->number} diselesaikan ({$data['action']}).");
    }
}
