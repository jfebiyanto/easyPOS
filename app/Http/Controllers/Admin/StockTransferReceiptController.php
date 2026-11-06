<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferShipment;
use App\Models\StockTransferShipmentLine;
use App\Support\ActivityLogger;
use App\Support\Sap\SapStockTransferPosting;
use App\Support\StockTransferException;
use App\Support\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class StockTransferReceiptController extends Controller
{
    use AuthorizesCompany, AuthorizesSuperuser, ScopesToStore;

    public function index(Request $request): View
    {
        $storeIds = $this->allowedStoreIds($request);

        return view('admin.stock.receipts.index', [
            'receipts' => StockTransferReceipt::where('company_id', current_company_id())
                ->where(function ($query) use ($storeIds) {
                    $query->whereIn('store_id', $storeIds)
                        ->orWhereHas('shipment', fn ($shipment) => $shipment->whereIn('from_store_id', $storeIds));
                })
                ->with(['shipment.fromStore', 'store'])
                ->withCount('lines')
                ->latest('received_at')
                ->paginate(15),
        ]);
    }

    public function show(Request $request, StockTransferReceipt $receipt): View
    {
        $this->ensureCompany($receipt->company_id);
        $receipt->loadMissing('shipment');

        $allowed = $this->allowedStoreIds($request);
        abort_unless(
            in_array($receipt->store_id, $allowed, true) || in_array($receipt->shipment?->from_store_id, $allowed, true),
            403,
        );

        return view('admin.stock.receipts.show', [
            'receipt' => $receipt->load(['shipment.fromStore', 'store', 'lines.shipmentLine.product']),
        ]);
    }

    public function create(Request $request): View
    {
        $shipmentId = $request->query('shipment');

        if (! is_string($shipmentId) || blank($shipmentId)) {
            return view('admin.stock.receipts.create', [
                'shipment' => null,
                'receivable' => $this->receivableShipments($request),
            ]);
        }

        $shipment = StockTransferShipment::findOrFail($shipmentId);
        $this->authorizeReceiving($request, $shipment);

        $shipment->load(['fromStore', 'toStore', 'lines.product']);
        $oldLines = old('lines');

        $lines = $shipment->lines
            ->filter(fn (StockTransferShipmentLine $line) => $line->remaining() > 0)
            ->filter(fn (StockTransferShipmentLine $line) => ! is_array($oldLines) || array_key_exists($line->id, $oldLines))
            ->values();

        return view('admin.stock.receipts.create', [
            'shipment' => $shipment,
            'lines' => $lines,
            'receivable' => collect(),
        ]);
    }

    public function store(Request $request, StockTransferService $service): RedirectResponse
    {
        $data = $request->validate([
            'shipment_id' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:254'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ], [
            'lines.required' => 'A receipt must have at least one line.',
            'lines.min' => 'A receipt must have at least one line.',
        ]);

        $shipment = StockTransferShipment::findOrFail($data['shipment_id']);
        $this->authorizeReceiving($request, $shipment);

        $quantities = collect($data['lines'])->map(fn (array $line) => (float) $line['qty'])->all();

        try {
            $receipt = $service->receive($shipment, $quantities, $request->user(), $data['note'] ?? null);
        } catch (StockTransferException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLogger::log('stock.receipt_created', "Received stock transfer shipment {$shipment->number} as receipt {$receipt->number}", $receipt);

        return redirect()->route('admin.stock-transfer-receipts.show', $receipt)->with('success', "Receipt {$receipt->number} created. Stock is now in the destination warehouse.");
    }

    /**
     * Kirim ulang ke SAP: Receipt yang belum terposting (failed, pending macet, atau belum pernah diantrekan).
     * Bila Shipment-nya belum terposting ke SAP, Receipt tetap pending dan dikirim setelah Shipment terposting.
     */
    public function retry(Request $request, StockTransferReceipt $receipt): RedirectResponse
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($receipt->company_id);
        abort_unless($receipt->sap_post_status !== StockTransferReceipt::SAP_POSTED, 422, 'This receipt is already posted to SAP.');

        SapStockTransferPosting::queue($receipt);

        if ($receipt->fresh()->sap_post_status !== StockTransferReceipt::SAP_PENDING) {
            return back()->with('error', 'SAP integration is inactive.');
        }

        ActivityLogger::log('stock.receipt_sap_retried', "Retried SAP posting for receipt {$receipt->number}", $receipt);

        $shipment = $receipt->shipment;

        if ($shipment?->sap_post_status !== StockTransferShipment::SAP_POSTED) {
            return back()->with('error', 'Receipt waits for shipment '.($shipment?->number ?? '-').' to be posted to SAP.');
        }

        return back()->with('success', 'SAP posting queued again.');
    }

    /**
     * Hanya gerai tujuan (atau Owner) yang boleh menerima Shipment yang sudah diposting.
     */
    private function authorizeReceiving(Request $request, StockTransferShipment $shipment): void
    {
        $this->ensureCompany($shipment->company_id);

        abort_unless(
            $shipment->status === StockTransferShipment::STATUS_POSTED
                && in_array($shipment->to_store_id, $this->allowedStoreIds($request), true),
            403,
        );
    }

    /**
     * Shipment posted ke gerai user yang masih punya baris bersisa.
     *
     * @return Collection<int, StockTransferShipment>
     */
    private function receivableShipments(Request $request): Collection
    {
        return StockTransferShipment::where('company_id', current_company_id())
            ->where('status', StockTransferShipment::STATUS_POSTED)
            ->whereIn('to_store_id', $this->allowedStoreIds($request))
            ->whereHas('lines', fn ($line) => $line->whereRaw('qty > qty_received + qty_returned'))
            ->with(['fromStore', 'toStore', 'lines'])
            ->latest('posted_at')
            ->get();
    }
}
