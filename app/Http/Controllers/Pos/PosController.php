<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\NumberingConfig;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDraft;
use App\Models\SalesReturn;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\StoreClosure;
use App\Support\ActivityLogger;
use App\Support\LoyaltyService;
use App\Support\PricingService;
use App\Support\SaleService;
use App\Support\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PosController extends Controller
{
    public function shell(Request $request): View
    {
        return view('pos.app', [
            'csrf' => csrf_token(),
            'cashier' => $request->user()->only(['id', 'name', 'role', 'store_id']),
        ]);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $company = CompanyDetail::current();
        $store = $this->store($request);

        abort_unless($store, 422, 'Kasir belum ditugaskan ke gerai.');

        $paymentTypes = PaymentType::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $store->id))
            ->orderBy('order_number')
            ->get(['id', 'code', 'description', 'count_type', 'is_offline_allowed']);

        return response()->json([
            'company' => [
                'name' => $company->name,
                'ppn_enabled' => (bool) $company->ppn_enabled,
                'ppn_rate' => (float) $company->ppn_rate,
                'ppn_inclusive' => (bool) $company->ppn_inclusive,
                'currency' => $company->base_currency_code,
                'allow_negative_stock' => (bool) $company->allow_negative_stock,
                'return_require_receipt' => (bool) $company->return_require_receipt,
                'return_window_days' => $company->return_window_days,
            ],
            'store' => ['id' => $store->id, 'code' => $store->code, 'name' => $store->name],
            'cashier' => $request->user()->only(['id', 'name', 'role']),
            'payment_types' => $paymentTypes,
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'Kasir belum ditugaskan ke gerai.');

        $query = Product::where('company_id', current_company_id())
            ->where('is_active', true)
            ->with('unit');

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%"));
        }

        $products = $query->orderBy('name')->limit(200)->get()->map(fn (Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'unit' => $p->unit?->code,
            'track_stock' => (bool) $p->track_stock,
            'price' => PricingService::resolve($store->id, $p, 1),
            'stock' => $p->track_stock ? StockService::balance($store->id, $p->id) : null,
        ]);

        return response()->json(['data' => $products]);
    }

    public function storeSale(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'Kasir belum ditugaskan ke gerai.');

        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'origin' => ['nullable', Rule::in(['online', 'offline'])],
            'sold_at' => ['nullable', 'date'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', current_company_id())],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', current_company_id())],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_type_id' => ['nullable', Rule::exists('payment_types', 'id')->where('company_id', current_company_id())],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.status' => ['nullable', Rule::in(['pending', 'confirmed'])],
            'delivery' => ['nullable', 'array'],
            'delivery.recipient_name' => ['required_with:delivery', 'string', 'max:150'],
            'delivery.address' => ['required_with:delivery', 'string', 'max:500'],
            'delivery.phone' => ['nullable', 'string', 'max:30'],
            'delivery.note' => ['nullable', 'string', 'max:500'],
        ]);

        $data['store_id'] = $store->id;

        // Kunci tanggal bisnis: transaksi online diblokir bila EOD hari sebelumnya belum selesai.
        if (($data['origin'] ?? 'online') !== 'offline') {
            $businessDate = company_business_date($data['sold_at'] ?? null);
            $locked = StoreClosure::unclosedPriorDate($store->id, $businessDate);
            abort_if($locked, 422, "Tutup toko tanggal {$locked} belum diselesaikan. Selesaikan EOD dulu.");
        }

        $sale = SaleService::create($data, $request->user());

        ActivityLogger::log('pos.sale_created', "Transaksi POS {$sale->number} sebesar {$sale->total}", $sale);

        return response()->json(['data' => $this->salePayload($sale)], 201);
    }

    public function showSale(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($sale->company_id === current_company_id(), 404);

        return response()->json(['data' => $this->salePayload($sale->load('lines', 'payments', 'delivery'))]);
    }

    public function voidSale(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($sale->company_id === current_company_id(), 404);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $sale = SaleService::void($sale, $request->user(), $data['reason']);

        ActivityLogger::log('pos.sale_void', "Void transaksi {$sale->number}", $sale);

        return response()->json(['data' => $this->salePayload($sale)]);
    }

    public function lookupSale(Request $request): JsonResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:60']]);

        $sale = Sale::where('company_id', current_company_id())
            ->where('number', $data['number'])
            ->with('lines')
            ->first();

        if (! $sale) {
            return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
        }

        $returned = DB::table('sales_return_lines')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
            ->where('sales_returns.original_sale_id', $sale->id)
            ->groupBy('sales_return_lines.sale_line_id')
            ->selectRaw('sales_return_lines.sale_line_id, sum(sales_return_lines.qty) as qty')
            ->pluck('qty', 'sale_line_id');

        $lines = $sale->lines->map(fn ($l) => [
            'sale_line_id' => $l->id,
            'product_id' => $l->product_id,
            'sku' => $l->sku_snapshot,
            'name' => $l->name_snapshot,
            'qty' => (float) $l->qty,
            'unit_price' => (float) $l->unit_price,
            'returned' => (float) ($returned[$l->id] ?? 0),
            'returnable' => max(0, (float) $l->qty - (float) ($returned[$l->id] ?? 0)),
        ]);

        return response()->json([
            'data' => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status,
                'sold_at' => $sale->sold_at?->toIso8601String(),
                'total' => (float) $sale->total,
                'lines' => $lines,
            ],
        ]);
    }

    public function storeReturn(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'Kasir belum ditugaskan ke gerai.');

        $data = $request->validate([
            'sale_id' => ['required', Rule::exists('sales', 'id')->where('company_id', current_company_id())],
            'reason' => ['nullable', 'string', 'max:500'],
            'refund_method' => ['nullable', 'string', 'max:30'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', Rule::exists('sale_lines', 'id')],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        $company = CompanyDetail::current();
        $sale = Sale::where('company_id', $company->id)->findOrFail($data['sale_id']);

        abort_if($sale->isVoid(), 422, 'Transaksi void tidak dapat diretur.');

        if ($company->return_window_days && $sale->sold_at && $sale->sold_at->diffInDays(now()) > $company->return_window_days) {
            abort(422, 'Retur melewati batas waktu yang diizinkan.');
        }

        $return = DB::transaction(function () use ($sale, $data, $store, $request) {
            $return = SalesReturn::create([
                'company_id' => $sale->company_id,
                'store_id' => $store->id,
                'number' => NumberingConfig::nextNumber('sales_return'),
                'original_sale_id' => $sale->id,
                'reason' => $data['reason'] ?? null,
                'refund_method' => $data['refund_method'] ?? null,
                'receipt_verified' => true,
                'created_by' => $request->user()->id,
                'total' => 0,
            ]);

            $total = 0.0;

            foreach ($data['lines'] as $row) {
                $line = $sale->lines()->whereKey($row['sale_line_id'])->firstOrFail();
                $qty = (float) $row['qty'];

                $already = (float) DB::table('sales_return_lines')
                    ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
                    ->where('sales_returns.original_sale_id', $sale->id)
                    ->where('sales_return_lines.sale_line_id', $line->id)
                    ->sum('sales_return_lines.qty');

                abort_if($qty > ((float) $line->qty - $already), 422, "Qty retur melebihi sisa untuk {$line->sku_snapshot}.");

                $lineTotal = round($qty * (float) $line->unit_price, 2);
                $total += $lineTotal;

                $return->lines()->create([
                    'sale_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'qty' => $qty,
                    'unit_price' => $line->unit_price,
                    'line_total' => $lineTotal,
                ]);

                if ($line->product_id) {
                    StockService::record($sale->store_id, $line->product_id, StockLedger::TYPE_SALE_RETURN, $qty, [
                        'company_id' => $sale->company_id,
                        'ref_type' => 'sales_return',
                        'ref_id' => $return->id,
                        'created_by' => $request->user()->id,
                    ]);
                }
            }

            $return->update(['total' => round($total, 2)]);

            LoyaltyService::reverseForReturn($return);

            return $return->load('lines');
        });

        ActivityLogger::log('pos.return_created', "Retur {$return->number} untuk transaksi {$sale->number}", $return);

        return response()->json(['data' => [
            'id' => $return->id,
            'number' => $return->number,
            'total' => (float) $return->total,
        ]], 201);
    }

    public function holds(Request $request): JsonResponse
    {
        $drafts = SaleDraft::where('company_id', current_company_id())
            ->where('cashier_id', $request->user()->id)
            ->where('status', 'held')
            ->latest('updated_at')
            ->get(['id', 'client_uuid', 'device_label', 'payload', 'updated_at']);

        return response()->json(['data' => $drafts]);
    }

    public function storeHold(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'Kasir belum ditugaskan ke gerai.');

        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'device_label' => ['nullable', 'string', 'max:100'],
            'payload' => ['required', 'array'],
        ]);

        $draft = SaleDraft::updateOrCreate(
            ['client_uuid' => $data['client_uuid']],
            [
                'company_id' => current_company_id(),
                'store_id' => $store->id,
                'cashier_id' => $request->user()->id,
                'device_label' => $data['device_label'] ?? null,
                'payload' => $data['payload'],
                'status' => 'held',
            ]
        );

        return response()->json(['data' => ['id' => $draft->id, 'client_uuid' => $draft->client_uuid]], 201);
    }

    public function destroyHold(Request $request, SaleDraft $draft): JsonResponse
    {
        abort_unless($draft->company_id === current_company_id(), 404);

        $draft->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Transaksi ditahan dibatalkan.']);
    }

    public function customers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $query = Customer::where('company_id', current_company_id())->with('memberLevel');

        if ($q !== '') {
            $query->where(fn ($x) => $x->where('phone', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%")
                ->orWhere('name', 'like', "%{$q}%"));
        }

        $data = $query->orderBy('name')->limit(20)->get()->map(fn (Customer $c) => $this->customerPayload($c));

        return response()->json(['data' => $data]);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'code' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        abort_if(blank($data['phone'] ?? null) && blank($data['code'] ?? null), 422, 'Nomor HP atau kode customer wajib diisi.');

        $companyId = current_company_id();

        if (filled($data['phone'] ?? null)) {
            abort_if(Customer::where('company_id', $companyId)->where('phone', $data['phone'])->exists(), 422, 'Nomor HP sudah terdaftar.');
        }
        if (blank($data['code'] ?? null)) {
            $data['code'] = 'CUST-'.str_pad((string) (Customer::where('company_id', $companyId)->count() + 1), 5, '0', STR_PAD_LEFT);
        } else {
            abort_if(Customer::where('company_id', $companyId)->where('code', $data['code'])->exists(), 422, 'Kode customer sudah dipakai.');
        }

        $customer = Customer::create($data + [
            'company_id' => $companyId,
            'is_member' => true,
            'points_balance' => 0,
            'member_level_id' => LoyaltyService::levelFor($companyId, 0)?->id,
            'joined_at' => now(),
            'is_active' => true,
        ]);

        ActivityLogger::log('customer.created', "Mendaftarkan customer {$customer->name} ({$customer->code})", $customer);

        return response()->json(['data' => $this->customerPayload($customer->load('memberLevel'))], 201);
    }

    private function customerPayload(Customer $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'phone' => $c->phone,
            'points_balance' => (float) $c->points_balance,
            'level' => $c->memberLevel?->name,
            'earn_amount_per_point' => (float) ($c->memberLevel?->earn_amount_per_point ?? 0),
        ];
    }

    private function store(Request $request): ?Store
    {
        $user = $request->user();

        return $user->store_id ? Store::find($user->store_id) : Store::orderBy('code')->first();
    }

    private function salePayload(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'number' => $sale->number,
            'status' => $sale->status,
            'origin' => $sale->origin,
            'stock_review_status' => $sale->stock_review_status,
            'subtotal' => (float) $sale->subtotal,
            'tax_total' => (float) $sale->tax_total,
            'shipping_cost' => (float) $sale->shipping_cost,
            'total' => (float) $sale->total,
            'paid_total' => (float) $sale->paid_total,
            'change_total' => (float) $sale->change_total,
            'currency' => $sale->currency_code,
            'points_earned' => (float) $sale->points_earned,
            'customer' => $sale->customer ? ['id' => $sale->customer->id, 'name' => $sale->customer->name, 'code' => $sale->customer->code] : null,
            'sold_at' => $sale->sold_at?->toIso8601String(),
            'lines' => $sale->lines->map(fn ($l) => [
                'sku' => $l->sku_snapshot,
                'name' => $l->name_snapshot,
                'qty' => (float) $l->qty,
                'unit_price' => (float) $l->unit_price,
                'tax_amount' => (float) $l->tax_amount,
                'line_total' => (float) $l->line_total,
            ]),
            'payments' => $sale->payments->map(fn ($p) => [
                'amount' => (float) $p->amount,
                'status' => $p->status,
            ]),
            'delivery' => $sale->delivery ? [
                'recipient_name' => $sale->delivery->recipient_name,
                'address' => $sale->delivery->address,
                'status' => $sale->delivery->status,
            ] : null,
        ];
    }
}
