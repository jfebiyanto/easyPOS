<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\NumberingConfig;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockLedger;
use App\Models\Store;
use App\Support\ActivityLogger;
use App\Support\StockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockAdjustmentController extends Controller
{
    use AuthorizesCompany, ScopesToStore;

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

            $before = StockService::balance($storeId, $line['product_id']);
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
        abort_if($stockAdjustment->isPosted(), 422, 'The adjustment has already been posted.');

        foreach ($stockAdjustment->lines as $line) {
            StockService::assertNonNegative($stockAdjustment->store_id, $line->product_id, (float) $line->delta);
        }

        foreach ($stockAdjustment->lines as $line) {
            StockService::record(
                $stockAdjustment->store_id,
                $line->product_id,
                StockLedger::TYPE_ADJUSTMENT,
                (float) $line->delta,
                [
                    'ref_type' => 'stock_adjustment',
                    'ref_id' => $stockAdjustment->id,
                    'note' => $stockAdjustment->reason,
                ]
            );
        }

        $stockAdjustment->update([
            'status' => StockAdjustment::STATUS_POSTED,
            'posted_at' => now(),
            'number' => NumberingConfig::nextNumber('stock_adjustment'),
        ]);

        ActivityLogger::log('stock.adjustment_posted', "Posting adjustment {$stockAdjustment->number}", $stockAdjustment);

        return redirect()->route('admin.stock-adjustments.index')->with('success', 'Adjustment posted successfully.');
    }
}
