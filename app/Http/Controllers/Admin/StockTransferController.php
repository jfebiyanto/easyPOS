<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\NumberingConfig;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Support\ActivityLogger;
use App\Support\StockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockTransferController extends Controller
{
    use ScopesToStore, AuthorizesCompany;

    public function index(Request $request): View
    {
        $storeIds = $this->allowedStoreIds($request);

        return view('admin.stock.transfers.index', [
            'transfers' => StockTransfer::where(function ($q) use ($storeIds) {
                $q->whereIn('from_store_id', $storeIds)->orWhereIn('to_store_id', $storeIds);
            })
                ->with(['fromStore', 'toStore', 'lines'])
                ->latest('created_at')
                ->paginate(15),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.stock.transfers.form', [
            'products' => Product::where('company_id', current_company_id())
                ->where('track_stock', true)
                ->orderBy('name')
                ->get(),
            'stores' => Store::where('company_id', current_company_id())->orderBy('code')->get(),
            'defaultFromStoreId' => $this->resolveStoreId($request),
        ]);
    }

    public function store(Request $request)
    {
        $fromId = $this->resolveStoreId($request, $request->input('from_store_id'));
        abort_unless($fromId && in_array($fromId, $this->allowedStoreIds($request), true), 422, 'Store asal tidak valid.');

        $data = $request->validate([
            'to_store_id' => ['required', 'different:from_store_id', Rule::exists('stores', 'id')->where('company_id', current_company_id())],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', current_company_id())],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $transfer = StockTransfer::create([
            'company_id' => current_company_id(),
            'from_store_id' => $fromId,
            'to_store_id' => $data['to_store_id'],
            'status' => StockTransfer::STATUS_DRAFT,
            'note' => $data['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $created = 0;
        foreach ($data['lines'] as $line) {
            if (blank($line['product_id'] ?? null) || (float) ($line['qty'] ?? 0) <= 0) {
                continue;
            }

            $transfer->lines()->create([
                'product_id' => $line['product_id'],
                'qty_requested' => (float) $line['qty'],
            ]);
            $created++;
        }

        if ($created === 0) {
            $transfer->delete();

            return back()->withInput()->with('error', 'Tidak ada baris valid untuk disimpan.');
        }

        ActivityLogger::log('stock.transfer_created', "Membuat draft transfer stok #{$transfer->id}", $transfer);

        return redirect()->route('admin.stock-transfers.index')->with('success', 'Transfer dibuat (draft).');
    }

    public function ship(Request $request, StockTransfer $stockTransfer)
    {
        $this->ensureCompany($stockTransfer->company_id);
        $this->authorizeTransfer($request, $stockTransfer);
        abort_unless(in_array($stockTransfer->status, [StockTransfer::STATUS_DRAFT, StockTransfer::STATUS_REQUESTED], true), 422, 'Transfer tidak dapat dikirim pada status ini.');

        foreach ($stockTransfer->lines as $line) {
            StockService::assertNonNegative($stockTransfer->from_store_id, $line->product_id, -(float) $line->qty_requested);
        }

        foreach ($stockTransfer->lines as $line) {
            StockService::record(
                $stockTransfer->from_store_id,
                $line->product_id,
                StockLedger::TYPE_TRANSFER_OUT,
                -(float) $line->qty_requested,
                ['ref_type' => 'stock_transfer', 'ref_id' => $stockTransfer->id]
            );
            $line->update(['qty_shipped' => $line->qty_requested]);
        }

        $stockTransfer->update([
            'status' => StockTransfer::STATUS_SHIPPED,
            'shipped_at' => now(),
            'number' => NumberingConfig::nextNumber('stock_transfer_shipment'),
        ]);

        ActivityLogger::log('stock.transfer_shipped', "Mengirim transfer {$stockTransfer->number}", $stockTransfer);

        return redirect()->route('admin.stock-transfers.index')->with('success', 'Transfer dikirim. Stok keluar dari gerai asal.');
    }

    public function receiveForm(Request $request, StockTransfer $stockTransfer): View
    {
        $this->ensureCompany($stockTransfer->company_id);
        $this->authorizeTransfer($request, $stockTransfer);
        abort_unless($stockTransfer->status === StockTransfer::STATUS_SHIPPED, 422, 'Transfer belum dikirim.');

        return view('admin.stock.transfers.receive', [
            'transfer' => $stockTransfer->load('lines.product', 'fromStore', 'toStore'),
        ]);
    }

    public function receive(Request $request, StockTransfer $stockTransfer)
    {
        $this->ensureCompany($stockTransfer->company_id);
        $this->authorizeTransfer($request, $stockTransfer);
        abort_unless($stockTransfer->status === StockTransfer::STATUS_SHIPPED, 422, 'Transfer belum dikirim.');

        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.id' => ['required', Rule::exists('stock_transfer_lines', 'id')->where('stock_transfer_id', $stockTransfer->id)],
            'lines.*.qty_received' => ['required', 'numeric', 'min:0'],
            'lines.*.variance_reason' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($data['lines'] as $row) {
            $line = $stockTransfer->lines()->whereKey($row['id'])->first();
            if (! $line) {
                continue;
            }

            $received = (float) $row['qty_received'];
            $shipped = (float) $line->qty_shipped;

            if ($received !== $shipped && blank($row['variance_reason'] ?? null)) {
                return back()->withInput()->withErrors(['lines' => "Alasan selisih wajib diisi untuk produk {$line->product?->sku}."]);
            }

            if ($received > 0) {
                StockService::record(
                    $stockTransfer->to_store_id,
                    $line->product_id,
                    StockLedger::TYPE_TRANSFER_IN,
                    $received,
                    ['ref_type' => 'stock_transfer', 'ref_id' => $stockTransfer->id]
                );
            }

            $line->update([
                'qty_received' => $received,
                'variance_reason' => $row['variance_reason'] ?? null,
            ]);
        }

        $stockTransfer->update([
            'status' => StockTransfer::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        ActivityLogger::log('stock.transfer_received', "Menerima transfer {$stockTransfer->number}", $stockTransfer);

        return redirect()->route('admin.stock-transfers.index')->with('success', 'Transfer diterima. Stok masuk ke gerai tujuan.');
    }

    public function cancel(Request $request, StockTransfer $stockTransfer)
    {
        $this->ensureCompany($stockTransfer->company_id);
        $this->authorizeTransfer($request, $stockTransfer);
        abort_if($stockTransfer->isShipped(), 422, 'Transfer yang sudah dikirim tidak dapat dibatalkan. Terima penuh lalu kirim balik.');

        $stockTransfer->update(['status' => StockTransfer::STATUS_CANCELLED]);

        ActivityLogger::log('stock.transfer_cancelled', "Membatalkan transfer #{$stockTransfer->id}", $stockTransfer);

        return redirect()->route('admin.stock-transfers.index')->with('success', 'Transfer dibatalkan.');
    }

    private function authorizeTransfer(Request $request, StockTransfer $transfer): void
    {
        abort_unless(
            in_array($transfer->from_store_id, $this->allowedStoreIds($request), true)
            || in_array($transfer->to_store_id, $this->allowedStoreIds($request), true),
            403
        );
    }
}
