<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\NumberingConfig;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\Promotion;
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
use App\Support\SaleStock;
use App\Support\Sap\SapCustomerSync;
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
                'tax_group_code' => $company->taxGroup?->code,
                'ppn_inclusive' => (bool) $company->ppn_inclusive,
                'currency' => $company->base_currency_code,
                'allow_negative_stock' => (bool) $company->allow_negative_stock,
                'return_require_receipt' => (bool) $company->return_require_receipt,
                'return_window_days' => $company->return_window_days,
            ],
            'store' => ['id' => $store->id, 'code' => $store->code, 'name' => $store->name],
            'default_customer' => $store->defaultCustomer?->only(['id', 'code', 'name']),
            'cashier' => $request->user()->only(['id', 'name', 'role']),
            'payment_types' => $paymentTypes,
            'business' => store_business_status($store->id),
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
            $query->where(fn ($q) => $q->whereLike('name', "%{$search}%")
                ->orWhereLike('sku', "%{$search}%")
                ->orWhereLike('barcode', "%{$search}%"));
        }

        $products = $query->orderBy('sku')->limit(200)->get()->map(fn (Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'unit' => $p->unit?->code,
            'image' => $p->image_url,
            'track_stock' => (bool) $p->track_stock,
            'price' => PricingService::resolve($store->id, $p, 1),
            'stock' => $p->track_stock ? SaleStock::balance($store->id, $p->id) : null,
        ]);

        return response()->json(['data' => $products]);
    }

    /**
     * Promosi untuk mesin promosi di kasir: yang aktif sekarang maupun yang baru mulai nanti,
     * agar tetap terhitung saat kasir offline. Disertai data produk bonus untuk tombol "Tambah bonus".
     */
    public function promotions(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'Kasir belum ditugaskan ke gerai.');

        $promotions = Promotion::where('company_id', current_company_id())
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->with('product', 'bonusProduct')
            ->get()
            ->map(function (Promotion $promotion) use ($store) {
                $bonusProduct = $promotion->type === 'buy_x_get_y'
                    ? ($promotion->bonusProduct ?? $promotion->product)
                    : null;

                return [
                    'id' => $promotion->id,
                    'code' => $promotion->code,
                    'name' => $promotion->name,
                    'type' => $promotion->type,
                    'value' => (float) $promotion->value,
                    'product_id' => $promotion->product_id,
                    'min_qty' => $promotion->min_qty,
                    'buy_qty' => $promotion->buy_qty,
                    'get_qty' => $promotion->get_qty,
                    'get_product_id' => $promotion->get_product_id,
                    'max_applications' => $promotion->max_applications,
                    'starts_at' => $promotion->starts_at?->toIso8601String(),
                    'ends_at' => $promotion->ends_at?->toIso8601String(),
                    'bonus_product' => $bonusProduct ? [
                        'id' => $bonusProduct->id,
                        'sku' => $bonusProduct->sku,
                        'name' => $bonusProduct->name,
                        'price' => PricingService::resolve($store->id, $bonusProduct, 1),
                        'track_stock' => (bool) $bonusProduct->track_stock,
                        'stock' => $bonusProduct->track_stock ? SaleStock::balance($store->id, $bonusProduct->id) : null,
                    ] : null,
                ];
            });

        return response()->json(['data' => $promotions]);
    }

    public function storeSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'store_id' => ['nullable', Rule::exists('stores', 'id')->where('company_id', current_company_id())],
            'origin' => ['nullable', Rule::in(['online', 'offline'])],
            'sold_at' => ['nullable', 'date'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', current_company_id())],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', current_company_id())],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.promotion_id' => ['nullable', Rule::exists('promotions', 'id')->where('company_id', current_company_id())],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_type_id' => ['nullable', Rule::exists('payment_types', 'id')->where('company_id', current_company_id())],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.status' => ['nullable', Rule::in(['pending', 'confirmed'])],
            'delivery' => ['nullable', 'array'],
            'delivery.recipient_name' => ['required_with:delivery', 'string', 'max:150'],
            'delivery.address' => ['required_with:delivery', 'string', 'max:500'],
            'delivery.phone' => ['nullable', 'string', 'max:30'],
            'delivery.note' => ['nullable', 'string', 'max:500'],
            'hold_client_uuid' => ['nullable', 'uuid'],
        ]);

        $holdClientUuid = $data['hold_client_uuid'] ?? null;
        unset($data['hold_client_uuid']);

        $assignedStore = $this->store($request);
        $originStoreId = $data['store_id'] ?? null;

        // Penjualan offline dicatat ke gerai tempatnya terjadi, walau kasir sudah dipindahkan sebelum sinkron.
        $store = ($data['origin'] ?? 'online') === 'offline' && $originStoreId
            ? Store::find($originStoreId)
            : $assignedStore;

        abort_unless($store, 422, 'Kasir belum ditugaskan ke gerai.');

        abort_if(
            ($data['origin'] ?? 'online') !== 'offline' && $originStoreId && $originStoreId !== $store->id,
            409,
            'Penugasan gerai Anda berubah. Muat ulang POS sebelum melanjutkan.'
        );

        $data['store_id'] = $store->id;

        // Kunci tanggal bisnis: transaksi online diblokir bila EOD hari sebelumnya belum selesai.
        if (($data['origin'] ?? 'online') !== 'offline') {
            $businessDate = company_business_date($data['sold_at'] ?? null);
            $locked = StoreClosure::unclosedPriorDate($store->id, $businessDate);
            abort_if($locked, 422, "Tutup toko tanggal {$locked} belum diselesaikan. Selesaikan EOD dulu.");
            abort_if(
                StoreClosure::isClosed($store->id, $businessDate),
                422,
                "EOD tanggal {$businessDate} sudah ditutup. Buka ulang EOD di Management Console untuk melanjutkan transaksi."
            );
        }

        $sale = SaleService::create($data, $request->user());

        if ($holdClientUuid) {
            SaleDraft::where('client_uuid', $holdClientUuid)
                ->where('company_id', current_company_id())
                ->where('cashier_id', $request->user()->id)
                ->whereIn('status', ['held', 'resumed'])
                ->update(['status' => 'finalized']);
        }

        ActivityLogger::log('pos.sale_created', "Transaksi POS {$sale->number} sebesar {$sale->total}", $sale);

        if ($store->id !== $assignedStore?->id) {
            ActivityLogger::log(
                'pos.sale_origin_store',
                "Transaksi offline {$sale->number} dicatat ke gerai asal {$store->code}, bukan gerai kasir saat ini",
                $sale,
                ['origin_store_id' => $store->id, 'assigned_store_id' => $assignedStore?->id],
            );
        }

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

                $already = DB::table('sales_return_lines')
                    ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
                    ->where('sales_returns.original_sale_id', $sale->id)
                    ->where('sales_return_lines.sale_line_id', $line->id)
                    ->selectRaw('coalesce(sum(sales_return_lines.qty), 0) as qty, coalesce(sum(sales_return_lines.line_total), 0) as amount')
                    ->first();

                $remainingQty = (float) $line->qty - (float) $already->qty;
                abort_if($qty > $remainingQty, 422, "Qty retur melebihi sisa untuk {$line->sku_snapshot}.");

                // Refund memakai harga bersih yang benar-benar dibayar (setelah promosi, termasuk PPN)
                // per unit. Sisa terakhir mengambil selisihnya agar total refund tidak melebihi baris.
                $netUnitPrice = (float) $line->line_total / (float) $line->qty;
                $lineTotal = abs($qty - $remainingQty) < 0.0005
                    ? round((float) $line->line_total - (float) $already->amount, 2)
                    : round($qty * $netUnitPrice, 2);
                $total += $lineTotal;

                $return->lines()->create([
                    'sale_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'qty' => $qty,
                    'unit_price' => round($netUnitPrice, 2),
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
            ->where('store_id', $this->store($request)?->id)
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

        $existing = SaleDraft::where('client_uuid', $data['client_uuid'])->first();

        abort_if(
            $existing && ($existing->company_id !== current_company_id() || $existing->cashier_id !== $request->user()->id),
            409,
            'ID transaksi ditahan sudah dipakai.'
        );
        abort_if($existing?->status === 'finalized', 409, 'Transaksi ditahan sudah menjadi penjualan.');

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

        $user = $request->user();
        abort_unless($draft->cashier_id === $user->id || $user->canAccessBackoffice(), 403);

        $draft->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Transaksi ditahan dibatalkan.']);
    }

    public function consumeHold(Request $request, SaleDraft $draft): JsonResponse
    {
        abort_unless($draft->company_id === current_company_id(), 404);
        abort_unless($draft->cashier_id === $request->user()->id, 403);
        abort_unless($draft->store_id === $this->store($request)?->id, 409, 'Transaksi ditahan ini milik gerai lain.');

        $consumed = SaleDraft::whereKey($draft->id)->where('status', 'held')->update(['status' => 'resumed']);
        abort_if($consumed === 0, 409, 'Transaksi ditahan sudah dilanjutkan atau dibatalkan.');

        return response()->json(['message' => 'Tahan diambil untuk dilanjutkan.', 'data' => [
            'id' => $draft->id,
            'client_uuid' => $draft->client_uuid,
            'stock' => $this->holdStock($draft),
        ]]);
    }

    /**
     * Stok terkini produk di keranjang hold (null = stok tidak dilacak), agar kasir
     * dapat menyesuaikan qty saat hold dilanjutkan. Menahan transaksi tidak memotong stok.
     *
     * @return array<string, float|null>
     */
    private function holdStock(SaleDraft $draft): array
    {
        $productIds = collect($draft->payload['cart'] ?? [])->pluck('id')->filter()->unique()->values()->all();
        $products = Product::where('company_id', current_company_id())->whereIn('id', $productIds)->get(['id', 'track_stock']);
        $balances = SaleStock::balances($draft->store_id, $products->where('track_stock', true)->pluck('id')->all());

        return $products->mapWithKeys(fn (Product $p) => [
            $p->id => $p->track_stock ? ($balances[$p->id] ?? 0.0) : null,
        ])->all();
    }

    /**
     * Mengembalikan hold yang sedang dilanjutkan ke daftar tahan bila keranjangnya
     * dikosongkan atau halaman dimuat ulang sebelum menjadi penjualan.
     */
    public function releaseHold(Request $request, SaleDraft $draft): JsonResponse
    {
        abort_unless($draft->company_id === current_company_id(), 404);
        abort_unless($draft->cashier_id === $request->user()->id, 403);

        SaleDraft::whereKey($draft->id)->where('status', 'resumed')->update(['status' => 'held']);

        return response()->json(['message' => 'Transaksi dikembalikan ke daftar tahan.']);
    }

    public function customers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $query = Customer::where('company_id', current_company_id())->with('memberLevel');

        if ($q !== '') {
            $query->where(fn ($x) => $x->whereLike('phone', "%{$q}%")
                ->orWhereLike('code', "%{$q}%")
                ->orWhereLike('name', "%{$q}%"));
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
        $sapActive = (bool) CompanyDetail::find($companyId)?->sapIntegrationActive();

        if ($sapActive) {
            // Kode customer = CardCode yang dibuat SAP B1 setelah customer terkirim.
            $data['code'] = null;
        } elseif (blank($data['code'] ?? null)) {
            $data['code'] = 'CUST-'.str_pad((string) (Customer::where('company_id', $companyId)->count() + 1), 5, '0', STR_PAD_LEFT);
        } else {
            abort_if(Customer::where('company_id', $companyId)->where('code', $data['code'])->exists(), 422, 'Kode customer sudah dipakai.');
        }

        // POS tidak memilih grup: pakai grup default (selama integrasi SAP aktif hanya grup dari SAP).
        $defaultGroup = CustomerGroup::defaultFor($companyId);
        if ($defaultGroup && (! $sapActive || $defaultGroup->isFromSap())) {
            $data['customer_group_id'] = $defaultGroup->id;
        }

        $customer = Customer::create($data + [
            'company_id' => $companyId,
            'is_member' => true,
            'points_balance' => 0,
            'member_level_id' => LoyaltyService::levelFor($companyId, 0)?->id,
            'joined_at' => now(),
            'is_active' => true,
            'source' => Customer::SOURCE_MANUAL,
        ]);

        ActivityLogger::log('customer.created', "Mendaftarkan customer {$customer->name} ({$customer->code})", $customer);

        SapCustomerSync::queue($customer);

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

    /**
     * Gerai yang dipakai user di POS. Hanya Owner yang boleh tanpa gerai dan memakai gerai pertama.
     */
    private function store(Request $request): ?Store
    {
        $user = $request->user();

        if ($user->store_id) {
            return Store::find($user->store_id);
        }

        return $user->isOwner() ? Store::orderBy('code')->first() : null;
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
            'discount_total' => (float) $sale->discount_total,
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
                'discount' => (float) $l->discount,
                'promotion_name' => $l->promotion_name_snapshot,
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
