<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\StockTransferReturn;
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

class StockTransferReturnController extends Controller
{
    use AuthorizesCompany, AuthorizesSuperuser, ScopesToStore;

    public function index(Request $request): View
    {
        $storeIds = $this->allowedStoreIds($request);

        return view('admin.stock.returns.index', [
            'returns' => StockTransferReturn::where('company_id', current_company_id())
                ->where(function ($query) use ($storeIds) {
                    $query->whereIn('store_id', $storeIds)
                        ->orWhereHas('shipment', fn ($shipment) => $shipment->whereIn('to_store_id', $storeIds));
                })
                ->with(['shipment.toStore', 'store'])
                ->withCount('lines')
                ->latest('returned_at')
                ->paginate(15),
        ]);
    }

    public function show(Request $request, StockTransferReturn $stockReturn): View
    {
        $this->ensureCompany($stockReturn->company_id);
        $stockReturn->loadMissing('shipment');

        $allowed = $this->allowedStoreIds($request);
        abort_unless(
            in_array($stockReturn->store_id, $allowed, true) || in_array($stockReturn->shipment?->to_store_id, $allowed, true),
            403,
        );

        return view('admin.stock.returns.show', [
            'stockReturn' => $stockReturn->load(['shipment.toStore', 'store', 'lines.shipmentLine.product']),
        ]);
    }

    public function create(Request $request): View
    {
        $shipmentId = $request->query('shipment');

        if (! is_string($shipmentId) || blank($shipmentId)) {
            return view('admin.stock.returns.create', [
                'shipment' => null,
                'returnable' => $this->returnableShipments($request),
            ]);
        }

        $shipment = StockTransferShipment::findOrFail($shipmentId);
        $this->authorizeReturning($request, $shipment);

        $shipment->load(['fromStore', 'toStore', 'lines.product']);
        $oldLines = old('lines');

        $lines = $shipment->lines
            ->filter(fn (StockTransferShipmentLine $line) => $line->remaining() > 0)
            ->filter(fn (StockTransferShipmentLine $line) => ! is_array($oldLines) || array_key_exists($line->id, $oldLines))
            ->values();

        return view('admin.stock.returns.create', [
            'shipment' => $shipment,
            'lines' => $lines,
            'returnable' => collect(),
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
            'lines.required' => 'A return must have at least one line.',
            'lines.min' => 'A return must have at least one line.',
        ]);

        $shipment = StockTransferShipment::findOrFail($data['shipment_id']);
        $this->authorizeReturning($request, $shipment);

        $quantities = collect($data['lines'])->map(fn (array $line) => (float) $line['qty'])->all();

        try {
            $stockReturn = $service->returnToSender($shipment, $quantities, $request->user(), $data['note'] ?? null);
        } catch (StockTransferException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLogger::log('stock.return_created', "Returned stock transfer shipment {$shipment->number} to sender as return {$stockReturn->number}", $stockReturn);

        return redirect()->route('admin.stock-transfer-returns.show', $stockReturn)->with('success', "Return {$stockReturn->number} created. Stock is back in the source warehouse.");
    }

    /**
     * Kirim ulang ke SAP: Return yang belum terposting (failed, pending macet, atau belum pernah diantrekan).
     * Bila Shipment-nya belum terposting ke SAP, Return tetap pending dan dikirim setelah Shipment terposting.
     */
    public function retry(Request $request, StockTransferReturn $stockReturn): RedirectResponse
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($stockReturn->company_id);
        abort_unless($stockReturn->sap_post_status !== StockTransferReturn::SAP_POSTED, 422, 'This return is already posted to SAP.');

        SapStockTransferPosting::queue($stockReturn);

        if ($stockReturn->fresh()->sap_post_status !== StockTransferReturn::SAP_PENDING) {
            return back()->with('error', 'SAP integration is inactive.');
        }

        ActivityLogger::log('stock.return_sap_retried', "Retried SAP posting for return {$stockReturn->number}", $stockReturn);

        $shipment = $stockReturn->shipment;

        if ($shipment?->sap_post_status !== StockTransferShipment::SAP_POSTED) {
            return back()->with('error', 'Return waits for shipment '.($shipment?->number ?? '-').' to be posted to SAP.');
        }

        return back()->with('success', 'SAP posting queued again.');
    }

    /**
     * Hanya gerai pengirim (atau Owner) yang boleh mengembalikan Shipment yang sudah diposting.
     */
    private function authorizeReturning(Request $request, StockTransferShipment $shipment): void
    {
        $this->ensureCompany($shipment->company_id);

        abort_unless(
            $shipment->status === StockTransferShipment::STATUS_POSTED
                && in_array($shipment->from_store_id, $this->allowedStoreIds($request), true),
            403,
        );
    }

    /**
     * Shipment posted dari gerai user yang masih punya baris bersisa.
     *
     * @return Collection<int, StockTransferShipment>
     */
    private function returnableShipments(Request $request): Collection
    {
        return StockTransferShipment::where('company_id', current_company_id())
            ->where('status', StockTransferShipment::STATUS_POSTED)
            ->whereIn('from_store_id', $this->allowedStoreIds($request))
            ->whereHas('lines', fn ($line) => $line->whereRaw('qty > qty_received + qty_returned'))
            ->with(['fromStore', 'toStore', 'lines'])
            ->latest('posted_at')
            ->get();
    }
}
