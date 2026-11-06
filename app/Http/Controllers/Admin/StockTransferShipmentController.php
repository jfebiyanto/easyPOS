<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockTransferShipment;
use App\Models\Store;
use App\Support\ActivityLogger;
use App\Support\Sap\SapStockTransferPosting;
use App\Support\StockTransferException;
use App\Support\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockTransferShipmentController extends Controller
{
    use AuthorizesCompany, AuthorizesSuperuser, ScopesToStore;

    public function index(Request $request): View
    {
        $storeIds = $this->allowedStoreIds($request);

        return view('admin.stock.shipments.index', [
            'shipments' => StockTransferShipment::where('company_id', current_company_id())
                ->where(function ($query) use ($storeIds) {
                    $query->whereIn('from_store_id', $storeIds)->orWhereIn('to_store_id', $storeIds);
                })
                ->with(['fromStore', 'toStore', 'lines'])
                ->latest('created_at')
                ->paginate(15),
        ]);
    }

    public function show(Request $request, StockTransferShipment $shipment): View
    {
        $this->authorizeShipment($request, $shipment, 'either');

        return view('admin.stock.shipments.show', [
            'shipment' => $shipment->load(['fromStore', 'toStore', 'lines.product']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.stock.shipments.form', $this->formData($request, new StockTransferShipment([
            'from_store_id' => $this->resolveStoreId($request),
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $fromId = $this->resolveStoreId($request, $request->input('from_store_id'));
        abort_unless($fromId && in_array($fromId, $this->allowedStoreIds($request), true), 422, 'Invalid source store.');

        $data = $this->validated($request, $fromId);

        $shipment = StockTransferShipment::create([
            'company_id' => current_company_id(),
            'from_store_id' => $fromId,
            'to_store_id' => $data['to_store_id'],
            'status' => StockTransferShipment::STATUS_DRAFT,
            'note' => $data['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        if ($this->syncLines($shipment, $data['lines']) === 0) {
            $shipment->delete();

            return back()->withInput()->with('error', 'There are no valid lines to save.');
        }

        ActivityLogger::log('stock.shipment_created', "Created stock transfer shipment draft #{$shipment->id}", $shipment);

        return redirect()->route('admin.stock-transfer-shipments.index')->with('success', 'Shipment created (draft). Post it to move the stock.');
    }

    public function edit(Request $request, StockTransferShipment $shipment): View
    {
        $this->authorizeShipment($request, $shipment, 'from');
        $this->ensureDraft($shipment);

        return view('admin.stock.shipments.form', $this->formData($request, $shipment->load('lines')));
    }

    public function update(Request $request, StockTransferShipment $shipment): RedirectResponse
    {
        $this->authorizeShipment($request, $shipment, 'from');
        $this->ensureDraft($shipment);

        $data = $this->validated($request, $shipment->from_store_id);

        $lineCount = $shipment->getConnection()->transaction(function () use ($shipment, $data) {
            $shipment->update(['to_store_id' => $data['to_store_id'], 'note' => $data['note'] ?? null]);
            $shipment->lines()->delete();

            return $this->syncLines($shipment, $data['lines']);
        });

        if ($lineCount === 0) {
            return back()->withInput()->with('error', 'There are no valid lines to save.');
        }

        ActivityLogger::log('stock.shipment_updated', "Updated stock transfer shipment draft #{$shipment->id}", $shipment);

        return redirect()->route('admin.stock-transfer-shipments.index')->with('success', 'Shipment updated.');
    }

    public function destroy(Request $request, StockTransferShipment $shipment): RedirectResponse
    {
        $this->authorizeShipment($request, $shipment, 'from');
        $this->ensureDraft($shipment);

        $shipment->delete();

        ActivityLogger::log('stock.shipment_deleted', "Deleted stock transfer shipment draft #{$shipment->id}", $shipment);

        return redirect()->route('admin.stock-transfer-shipments.index')->with('success', 'Shipment deleted.');
    }

    public function post(Request $request, StockTransferShipment $shipment, StockTransferService $service): RedirectResponse
    {
        $this->authorizeShipment($request, $shipment, 'from');

        try {
            $service->post($shipment, $request->user());
        } catch (StockTransferException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        ActivityLogger::log('stock.shipment_posted', "Posted stock transfer shipment {$shipment->number}", $shipment);

        return redirect()->route('admin.stock-transfer-shipments.index')->with('success', "Shipment {$shipment->number} posted. Stock is now in transit.");
    }

    public function retry(Request $request, StockTransferShipment $shipment): RedirectResponse
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($shipment->company_id);
        abort_unless($shipment->sap_post_status === StockTransferShipment::SAP_FAILED, 422, 'Only shipments with a failed SAP posting can be retried.');

        SapStockTransferPosting::queue($shipment);

        ActivityLogger::log('stock.shipment_sap_retried', "Retried SAP posting for shipment {$shipment->number}", $shipment);

        return back()->with('success', 'SAP posting queued again.');
    }

    /**
     * Pengirim ('from') yang boleh mengubah/memposting; kedua sisi boleh melihat. Owner bebas.
     *
     * @param  'from'|'either'  $side
     */
    private function authorizeShipment(Request $request, StockTransferShipment $shipment, string $side): void
    {
        $this->ensureCompany($shipment->company_id);

        $allowed = $this->allowedStoreIds($request);

        $permitted = in_array($shipment->from_store_id, $allowed, true)
            || ($side === 'either' && in_array($shipment->to_store_id, $allowed, true));

        abort_unless($permitted, 403);
    }

    private function ensureDraft(StockTransferShipment $shipment): void
    {
        abort_unless($shipment->status === StockTransferShipment::STATUS_DRAFT, 422, 'Only draft shipments can be changed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $fromStoreId): array
    {
        return $request->validate([
            'to_store_id' => ['required', Rule::notIn([$fromStoreId]), Rule::exists('stores', 'id')->where('company_id', current_company_id())],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', current_company_id())],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncLines(StockTransferShipment $shipment, array $lines): int
    {
        $created = 0;

        foreach ($lines as $line) {
            if (blank($line['product_id'] ?? null) || (float) ($line['qty'] ?? 0) <= 0) {
                continue;
            }

            $shipment->lines()->create(['product_id' => $line['product_id'], 'qty' => (float) $line['qty'], 'qty_received' => 0]);
            $created++;
        }

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, StockTransferShipment $shipment): array
    {
        return [
            'shipment' => $shipment,
            'fromStore' => Store::find($shipment->from_store_id),
            'destinations' => Store::where('company_id', current_company_id())
                ->where('id', '!=', $shipment->from_store_id)
                ->orderBy('code')
                ->get(),
            'products' => Product::where('company_id', current_company_id())
                ->where('track_stock', true)
                ->orderBy('sku')
                ->get(),
        ];
    }
}
