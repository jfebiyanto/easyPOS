<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToStore;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Currency;
use App\Models\PaymentType;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreClosure;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StoreClosureController extends Controller
{
    use AuthorizesCompany, ScopesToStore;

    public function index(Request $request): View
    {
        $storeId = $this->resolveStoreId($request, $request->query('store'));
        $businessDate = $request->query('date') ?: company_today();

        $store = $storeId ? Store::find($storeId) : null;
        $company = CompanyDetail::current();

        $salesTotal = $store ? (float) Sale::where('store_id', $store->id)
            ->whereDate('business_date', $businessDate)
            ->where('status', Sale::STATUS_COMPLETED)
            ->sum('total') : 0;

        $cashExpected = $store ? (float) DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.store_id', $store->id)
            ->whereDate('sales.business_date', $businessDate)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('sale_payments.status', 'confirmed')
            ->whereIn('sale_payments.payment_type_id', PaymentType::where('company_id', $company->id)->where('kind', PaymentType::KIND_CASH)->pluck('id'))
            ->sum('sale_payments.amount') : 0;

        $reviewCount = $store ? Sale::where('store_id', $store->id)
            ->whereDate('business_date', $businessDate)
            ->where('stock_review_status', 'pending')
            ->count() : 0;

        $denominations = Currency::where('company_id', $company->id)
            ->where('code', $company->base_currency_code)
            ->first()
            ?->denominations()
            ->where('is_active', true)
            ->orderByDesc('value')
            ->get() ?? collect();

        return view('admin.eod.index', [
            'store' => $store,
            'storeId' => $storeId,
            'businessDate' => $businessDate,
            'salesTotal' => $salesTotal,
            'cashExpected' => $cashExpected,
            'reviewCount' => $reviewCount,
            'denominations' => $denominations,
            'stores' => $this->reportStores($request),
            'closure' => $store ? StoreClosure::where('store_id', $store->id)->whereDate('business_date', $businessDate)->first() : null,
        ]);
    }

    public function store(Request $request)
    {
        $storeId = $this->resolveStoreId($request, $request->input('store_id'));
        abort_unless($storeId && in_array($storeId, $this->allowedStoreIds($request), true), 422, 'Invalid store.');

        $data = $request->validate([
            'business_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'denominations' => ['required', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
            'override_review' => ['sometimes', 'boolean'],
        ]);

        $company = CompanyDetail::current();
        $store = Store::findOrFail($storeId);
        $businessDate = $data['business_date'];

        if (StoreClosure::isClosed($store->id, $businessDate)) {
            return back()->withInput()->with('error', "EOD for {$businessDate} is already closed. Reopen EOD first.");
        }

        $pending = Sale::where('store_id', $store->id)
            ->whereDate('business_date', $businessDate)
            ->where('stock_review_status', 'pending')
            ->count();

        if ($pending > 0 && ! ($request->boolean('override_review') && $request->user()->isOwner())) {
            return back()->withInput()->with('error', "There are still {$pending} transactions pending review.");
        }

        $salesTotal = (float) Sale::where('store_id', $store->id)
            ->whereDate('business_date', $businessDate)
            ->where('status', Sale::STATUS_COMPLETED)
            ->sum('total');

        $cashExpected = (float) DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.store_id', $store->id)
            ->whereDate('sales.business_date', $businessDate)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('sale_payments.status', 'confirmed')
            ->whereIn('sale_payments.payment_type_id', PaymentType::where('company_id', $company->id)->where('kind', PaymentType::KIND_CASH)->pluck('id'))
            ->sum('sale_payments.amount');

        $cashCounted = 0.0;
        DB::transaction(function () use ($store, $company, $businessDate, $salesTotal, $cashExpected, $data, $request, &$cashCounted) {
            $closure = StoreClosure::where('store_id', $store->id)
                ->whereDate('business_date', $businessDate)
                ->first();

            if ($closure) {
                $closure->update([
                    'closed_at' => now(),
                    'sales_total' => $salesTotal,
                    'cash_expected' => $cashExpected,
                    'closed_by' => $request->user()->id,
                    'note' => $data['note'] ?? null,
                ]);
            } else {
                $closure = StoreClosure::create([
                    'company_id' => $company->id,
                    'store_id' => $store->id,
                    'business_date' => $businessDate,
                    'opened_at' => now(),
                    'closed_at' => now(),
                    'sales_total' => $salesTotal,
                    'cash_expected' => $cashExpected,
                    'closed_by' => $request->user()->id,
                    'note' => $data['note'] ?? null,
                ]);
            }

            $closure->denominations()->delete();
            foreach ($data['denominations'] as $value => $count) {
                $count = (int) $count;
                if ($count <= 0) {
                    continue;
                }
                $subtotal = (float) $value * $count;
                $cashCounted += $subtotal;
                $closure->denominations()->create([
                    'denomination_value' => (float) $value,
                    'count' => $count,
                    'subtotal' => $subtotal,
                ]);
            }

            $closure->update([
                'cash_counted' => $cashCounted,
                'cash_variance' => round($cashCounted - $cashExpected, 2),
            ]);
        });

        ActivityLogger::log('store.closed', "Tutup toko {$store->code} tanggal {$businessDate}", $store);

        return redirect()->route('admin.eod.index', ['store' => $store->id, 'date' => $businessDate])
            ->with('success', 'Store closed successfully. Counted cash: Rp '.number_format($cashCounted, 0, ',', '.'));
    }

    /**
     * Buka ulang EOD: hanya untuk tanggal bisnis hari ini (tanggal sistem) dan wajib beralasan.
     */
    public function reopen(Request $request): RedirectResponse
    {
        $storeId = $this->resolveStoreId($request, $request->input('store_id'));
        abort_unless($storeId && in_array($storeId, $this->allowedStoreIds($request), true), 422, 'Invalid store.');

        $data = $request->validate([
            'business_date' => ['required', 'date'],
            'reopen_reason' => ['required', 'string', 'max:500'],
        ]);

        $store = Store::findOrFail($storeId);
        $businessDate = Carbon::parse($data['business_date'])->toDateString();

        if ($businessDate !== company_today()) {
            return back()->with('error', "EOD for {$businessDate} cannot be reopened. Only EOD for today's business date (".company_today().') can be reopened.');
        }

        $closure = StoreClosure::where('store_id', $store->id)
            ->whereDate('business_date', $businessDate)
            ->whereNotNull('closed_at')
            ->first();

        if (! $closure) {
            return back()->with('error', "EOD for {$businessDate} has not been closed.");
        }

        $closure->update([
            'closed_at' => null,
            'reopened_at' => now(),
            'reopened_by' => $request->user()->id,
            'reopen_reason' => $data['reopen_reason'],
        ]);

        ActivityLogger::log('store.reopened', "Buka ulang EOD {$store->code} tanggal {$businessDate}: {$data['reopen_reason']}", $store);

        return redirect()->route('admin.eod.index', ['store' => $store->id, 'date' => $businessDate])
            ->with('success', 'EOD reopened successfully. POS transactions can continue.');
    }

    private function reportStores(Request $request): Collection
    {
        if (! $request->user()->isOwner()) {
            return collect();
        }

        return Store::where('company_id', current_company_id())->orderBy('code')->get();
    }
}
