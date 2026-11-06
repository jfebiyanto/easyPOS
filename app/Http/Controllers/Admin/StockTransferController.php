<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman baca-saja untuk transfer satu langkah lama; alur baru ada di Shipment/Receipt.
 */
class StockTransferController extends Controller
{
    use ScopesToStore;

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
}
