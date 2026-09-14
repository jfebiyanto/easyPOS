<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    use ScopesToStore, AuthorizesCompany;

    public const STATUSES = ['pending', 'shipped', 'delivered', 'cancelled'];

    public function index(Request $request): View
    {
        $storeIds = $this->allowedStoreIds($request);

        $query = Delivery::query()
            ->whereHas('sale', fn ($q) => $q->whereIn('store_id', $storeIds))
            ->with(['sale.store', 'sale.customer']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('admin.deliveries.index', [
            'deliveries' => $query->latest('created_at')->paginate(15)->withQueryString(),
            'statuses' => self::STATUSES,
            'selectedStatus' => $request->query('status'),
        ]);
    }

    public function update(Request $request, Delivery $delivery)
    {
        $delivery->loadMissing('sale');
        $this->ensureCompany($delivery->sale?->company_id);
        abort_unless(in_array($delivery->sale?->store_id, $this->allowedStoreIds($request), true), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $update = ['status' => $data['status'], 'note' => $data['note'] ?? $delivery->note];

        if ($data['status'] === 'shipped' && ! $delivery->shipped_at) {
            $update['shipped_at'] = now();
        }
        if ($data['status'] === 'delivered' && ! $delivery->delivered_at) {
            $update['delivered_at'] = now();
        }

        $delivery->update($update);

        ActivityLogger::log('delivery.updated', "Ubah status pengiriman #{$delivery->id} menjadi {$data['status']}", $delivery);

        return back()->with('success', 'Status pengiriman diperbarui.');
    }
}
