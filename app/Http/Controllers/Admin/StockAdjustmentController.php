<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\NumberingConfig;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockLedger;
use App\Models\Store;
use App\Support\ActivityLogger;
use App\Support\SaleStock;
use App\Support\Sap\SapStockAdjustmentPosting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockAdjustmentController extends Controller
{
    use AuthorizesCompany, AuthorizesSuperuser, ScopesToStore;

    public function index(Request $request): View
    {
        $storeIds = $this->allowedStoreIds($request);

        return view('admin.stock.adjustments.index', [
            'adjustments' => StockAdjustment::whereIn('store_id', $storeIds)
                ->with(['store', 'lines'])
                ->latest('created_at')
                ->paginate(15),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.stock.adjustments.form', [
            'products' => Product::where('company_id', current_company_id())
                ->where('track_stock', true)
                ->orderBy('sku')
                ->get(),
            'stores' => $request->user()->isOwner()
                ? Store::where('company_id', current_company_id())->orderBy('code')->get()
                : collect(),
            'defaultStoreId' => $this->resolveStoreId($request),
        ]);
    }

    public function store(Request $request)
    {
        $storeId = $this->resolveStoreId($request, $request->input('store_id'));
        abort_unless($storeId && in_array($storeId, $this->allowedStoreIds($request), true), 422, 'Invalid store.');

        $data = $request->validate([
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', current_company_id())],
            'lines.*.qty_after' => ['nullable', 'numeric'],
        ]);

        $adjustment = StockAdjustment::create([
            'company_id' => current_company_id(),
            'store_id' => $storeId,
            'date' => $data['date'],
            'reason' => $data['reason'] ?? null,
            'status' => StockAdjustment::STATUS_DRAFT,
            'created_by' => auth()->id(),
        ]);

        $created = 0;
        foreach ($data['lines'] as $line) {
            if (blank($line['product_id'] ?? null) || ($line['qty_after'] ?? '') === '') {
                continue;
            }

            $before = SaleStock::balance($storeId, $line['product_id']);
            $after = (float) $line['qty_after'];

            $adjustment->lines()->create([
                'product_id' => $line['product_id'],
                'qty_before' => $before,
                'qty_after' => $after,
                'delta' => $after - $before,
            ]);
            $created++;
        }

        if ($created === 0) {
            $adjustment->delete();

            return back()->withInput()->with('error', 'There are no valid lines to save.');
        }

        ActivityLogger::log('stock.adjustment_created', "Membuat draft adjustment #{$adjustment->id}", $adjustment);

        return redirect()->route('admin.stock-adjustments.index')->with('success', 'Adjustment created (draft). Post it to apply.');
    }

    public function post(Request $request, StockAdjustment $stockAdjustment)
    {
        $this->ensureCompany($stockAdjustment->company_id);
        abort_unless(in_array($stockAdjustment->store_id, $this->allowedStoreIds($request), true), 403);

        // Gerai berwarehouse memakai buku besar warehouse dan wajib diposting ke SAP B1 (seperti transfer stok).
        $hasWarehouse = SaleStock::warehouseFor($stockAdjustment->store_id) !== null;
        if ($hasWarehouse && ! CompanyDetail::find($stockAdjustment->company_id)?->sapIntegrationActive()) {
            return back()->with('error', 'SAP integration is not active; stock adjustments of a store with a warehouse cannot be posted.');
        }

        DB::transaction(function () use ($stockAdjustment) {
            $locked = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->with('lines')->firstOrFail();
            abort_if($locked->isPosted(), 422, 'The adjustment has already been posted.');

            foreach ($locked->lines as $line) {
                SaleStock::assertAvailable($locked->store_id, $line->product_id, (float) $line->delta);
            }

            foreach ($locked->lines as $line) {
                if ((float) $line->delta === 0.0) {
                    continue;
                }

                SaleStock::record($locked->store_id, $line->product_id, StockLedger::TYPE_ADJUSTMENT, (float) $line->delta, [
                    'company_id' => $locked->company_id,
                    'ref_type' => 'stock_adjustment',
                    'ref_id' => $locked->id,
                    'note' => $locked->reason,
                    'created_by' => auth()->id(),
                ]);
            }

            $locked->update([
                'status' => StockAdjustment::STATUS_POSTED,
                'posted_at' => now(),
                'number' => NumberingConfig::nextNumber('stock_adjustment'),
            ]);

            SapStockAdjustmentPosting::queue($locked);

            ActivityLogger::log('stock.adjustment_posted', "Posting adjustment {$locked->number}", $locked);
        });

        return redirect()->route('admin.stock-adjustments.index')->with('success', 'Adjustment posted successfully.');
    }

    public function retry(Request $request, StockAdjustment $stockAdjustment)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($stockAdjustment->company_id);
        abort_unless($stockAdjustment->isPosted(), 422, 'Only posted adjustments can be sent to SAP B1.');
        abort_if($stockAdjustment->sap_post_status === StockAdjustment::SAP_POSTED, 422, 'The adjustment has already been posted to SAP B1.');

        if (! CompanyDetail::find($stockAdjustment->company_id)?->sapIntegrationActive()) {
            return back()->with('error', 'SAP integration is not active.');
        }

        SapStockAdjustmentPosting::queue($stockAdjustment);

        return back()->with('success', "Adjustment {$stockAdjustment->number} scheduled to be sent to SAP B1.");
    }
}
