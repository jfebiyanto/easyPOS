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
        $store = $storeId ? Store::find($storeId) : null;

        // Tanpa pilihan tanggal: tanggal bisnis yang berjalan, yaitu yang tertua dan belum di-EOD (tidak berganti sebelum ditutup).
        $businessDate = $request->query('date') ?: ($store ? StoreClosure::postingDate($store->id, company_today()) : company_today());

        $company = CompanyDetail::current();

        $salesTotal = $store ? (float) Sale::where('store_id', $store->id)
            ->whereDate('business_date', $businessDate)
            ->where('status', Sale::STATUS_COMPLETED)
            ->sum('total') : 0;

        $cashExpected = $store ? StoreClosure::expectedCash($store->id, $company->id, $businessDate) : 0.0;

        $reviewCount = $store ? Sale::where('store_id', $store->id)
            ->whereDate('business_date', $businessDate)
            ->where('stock_review_status', 'pending')
            ->count() : 0;

        $countByDenomination = $store && $this->requiresDenominationCount($store, $company->id);

        $denominations = ! $countByDenomination ? collect() : Currency::where('company_id', $company->id)
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
            'countByDenomination' => $countByDenomination,
            'paymentLines' => $store ? $this->paymentLines($store, $company->id, $businessDate) : collect(),
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
            'denominations' => ['array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
            'override_review' => ['sometimes', 'boolean'],
        ]);

        $company = CompanyDetail::current();
        $store = Store::findOrFail($storeId);
        $businessDate = $data['business_date'];
        $countByDenomination = $this->requiresDenominationCount($store, $company->id);

        if (StoreClosure::isClosed($store->id, $businessDate)) {
            return back()->withInput()->with('error', "EOD for {$businessDate} is already closed. Reopen EOD first.");
        }

        // Setiap tipe pembayaran wajib dikonfirmasi; tipe tunai yang dihitung per pecahan dikonfirmasi lewat hitungan itu.
        $unconfirmed = $this->paymentLines($store, $company->id, $businessDate)
            ->filter(fn (array $line) => ! ($line['counted_by_denomination'] ?? false) && ! $request->boolean('confirm.'.$line['id']))
            ->pluck('code');

        if ($unconfirmed->isNotEmpty()) {
            return back()->withInput()->withErrors(['confirm' => 'Confirm every payment method before closing: '.$unconfirmed->implode(', ').'.']);
        }

        if ($countByDenomination && empty($data['denominations'])) {
            return back()->withInput()->withErrors(['denominations' => 'Enter the cash count per denomination.']);
        }

        $unclosedPrior = StoreClosure::unclosedPriorDate($store->id, Carbon::parse($businessDate)->toDateString());
        if ($unclosedPrior) {
            return back()->withInput()->with('error', "EOD for {$unclosedPrior} has not been closed. Close it first.");
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

        $cashExpected = StoreClosure::expectedCash($store->id, $company->id, $businessDate);

        $cashCounted = 0.0;
        DB::transaction(function () use ($store, $company, $businessDate, $salesTotal, $cashExpected, $data, $request, $countByDenomination, &$cashCounted) {
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

            $closure->payments()->delete();
            foreach ($this->paymentLines($store, $company->id, $businessDate) as $line) {
                $closure->payments()->create([
                    'payment_type_id' => $line['id'],
                    'payment_code' => $line['code'],
                    'kind' => $line['kind'],
                    'expected_amount' => $line['expected'],
                    'confirmed_at' => now(),
                ]);
            }

            $closure->denominations()->delete();
            foreach ($countByDenomination ? $data['denominations'] : [] as $value => $count) {
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

            // Count Type Summary: kas cukup dikonfirmasi, dianggap sama dengan yang diharapkan.
            if (! $countByDenomination) {
                $cashCounted = $cashExpected;
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

    /**
     * Daftar tipe pembayaran yang harus dikonfirmasi pada EOD: semua tipe aktif gerai ini ditambah tipe
     * (mis. sudah nonaktif) yang masih punya pembayaran pada tanggal itu, beserta nominal yang diharapkan.
     *
     * @return Collection<int, array{id: string, code: string, description: ?string, kind: string, count_type: string, expected: float, counted_by_denomination: bool}>
     */
    private function paymentLines(Store $store, string $companyId, string $businessDate): Collection
    {
        $expected = StoreClosure::expectedByPaymentType($store->id, $businessDate);
        $countByDenomination = $this->requiresDenominationCount($store, $companyId);

        return PaymentType::where('company_id', $companyId)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('is_active', true)->whereHas('stores', fn ($q) => $q->where('stores.id', $store->id)))
                ->orWhereIn('id', array_keys($expected)))
            ->orderBy('order_number')
            ->get()
            ->map(fn (PaymentType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'description' => $type->description,
                'kind' => $type->kind,
                'count_type' => $type->count_type,
                'expected' => $expected[$type->id] ?? 0.0,
                'counted_by_denomination' => $countByDenomination && $type->isCash(),
            ])
            ->values();
    }

    /**
     * Hitung kas per pecahan hanya bila ada tipe pembayaran tunai aktif gerai ini ber-Count Type Detail;
     * selain itu (Summary) kas cukup dikonfirmasi tanpa menghitung pecahan.
     */
    private function requiresDenominationCount(Store $store, string $companyId): bool
    {
        return PaymentType::where('company_id', $companyId)
            ->where('kind', PaymentType::KIND_CASH)
            ->where('is_active', true)
            ->where('count_type', 'detail')
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $store->id))
            ->exists();
    }

    private function reportStores(Request $request): Collection
    {
        if (! $request->user()->isOwner()) {
            return collect();
        }

        return Store::where('company_id', current_company_id())->orderBy('code')->get();
    }
}
