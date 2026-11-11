<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\NumberingConfig;
use App\Models\PaymentType;
use App\Models\PosActiveCart;
use App\Models\PosCancellation;
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
use App\Support\Sap\SapReturnPosting;
use App\Support\ShiftService;
use App\Support\StockService;
use DomainException;
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

        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

        $paymentTypes = PaymentType::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $store->id))
            ->orderBy('order_number')
            ->get(['id', 'code', 'description', 'count_type', 'is_offline_allowed', 'credit_card_id', 'kind', 'is_qr_payment', 'qr_image_path'])
            // Tipe yang dipetakan ke kartu kredit SAP (QRIS, EDC/kartu) mencatat kode approval.
            ->map(fn (PaymentType $type) => $type->only(['id', 'code', 'description', 'count_type', 'is_offline_allowed']) + ['requires_approval' => $type->credit_card_id !== null, 'is_cash' => $type->isCash(), 'qr_image_url' => $type->qr_image_url]);

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
            'default_customer' => $store->defaultCustomer ? $store->defaultCustomer->only(['id', 'code', 'name']) + ['group_code' => $store->defaultCustomer->customerGroup?->code] : null,
            'cashier' => $request->user()->only(['id', 'name', 'role']),
            'payment_types' => $paymentTypes,
            'business' => store_business_status($store->id),
            'shift_gate' => ShiftService::gate($store, $request->user()),
            'shift' => ShiftService::summary($request->user(), $store),
            'prompt' => $this->sessionPrompt($request, $store),
        ]);
    }

    /**
     * Open Store: kasir membuka toko untuk business date aktif sekaligus memulai shift-nya dengan menghitung modal awal.
     */
    public function openStore(Request $request): JsonResponse
    {
        return $this->beginShift($request, opening: true);
    }

    /**
     * Mulai shift kasir pada toko yang sudah dibuka kasir lain (atau kasir ini di shift sebelumnya).
     */
    public function startShift(Request $request): JsonResponse
    {
        return $this->beginShift($request, opening: false);
    }

    /**
     * Akhiri shift kasir ini (toko tetap terbuka sampai EOD).
     */
    public function endShift(Request $request): JsonResponse
    {
        ShiftService::endShift($request->user(), CashierShift::ENDED_LOGOUT);

        return response()->json(['data' => ['ended' => true]]);
    }

    private function beginShift(Request $request, bool $opening): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

        $data = $request->validate([
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $opening
                ? ShiftService::openStore($store, $request->user(), $data)
                : ShiftService::startShift($store, $request->user(), $data);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ShiftService::gate($store, $request->user()) + ['shift' => ShiftService::summary($request->user(), $store)]]);
    }

    /**
     * Konfirmasi awal sesi: kasir menyetujui pencatatan ke tanggal bisnis yang belum di-EOD
     * dan menutup sapaan login. Menolak konfirmasi dilakukan dengan keluar (logout) dari POS.
     */
    public function acknowledgeSession(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

        $lockedDate = store_business_status($store->id)['locked_date'];
        if ($lockedDate) {
            $request->session()->put('pos_backdate_ack', $store->id.'|'.$lockedDate);
        }
        $request->session()->forget('pos_greeting');

        return response()->json(['data' => $this->sessionPrompt($request, $store)]);
    }

    /**
     * @return array{greeting: ?string, backdate_date: ?string, posting_date: string}
     */
    private function sessionPrompt(Request $request, Store $store): array
    {
        $status = store_business_status($store->id);
        $needsBackdateAck = $status['locked_date'] && ! $this->backdateAcknowledged($store->id, $status['locked_date']);

        return [
            'greeting' => $request->session()->get('pos_greeting'),
            'backdate_date' => $needsBackdateAck ? $status['locked_date'] : null,
            'posting_date' => $status['posting_date'],
        ];
    }

    private function backdateAcknowledged(string $storeId, string $date): bool
    {
        return session('pos_backdate_ack') === $storeId.'|'.$date;
    }

    public function products(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

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
            'brand_id' => $p->brand_id,
            'product_group_id' => $p->product_group_id,
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
        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

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
                    'source' => $promotion->source,
                    'brand_id' => $promotion->brand_id,
                    'product_group_id' => $promotion->product_group_id,
                    'customer_scope' => $promotion->customer_scope,
                    'customer_ref' => $promotion->customer_ref,
                    'price_list_code' => $promotion->price_list_code,
                    'tiers' => $promotion->tiers,
                    'group_key' => $promotion->group_key,
                    'group_relation' => $promotion->group_relation,
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

        return response()->json([
            'data' => $promotions,
            'meta' => [
                'hierarchy' => CompanyDetail::current()->promotionHierarchy(),
                'price_list_code' => PricingService::activeListCode($store->id),
            ],
        ]);
    }

    /**
     * Simpan otomatis keranjang yang sedang berjalan (satu per kasir). Keranjang kosong menghapus simpanan.
     */
    public function saveCart(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

        $request->validate([
            'payload' => ['required', 'array'],
            'payload.cart' => ['present', 'array', 'max:300'],
            'payload.cart.*.id' => ['required', 'string'],
            'payload.cart.*.qty' => ['required', 'numeric', 'min:0'],
            'payload.cart.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $payload = $request->input('payload');
        abort_if(strlen((string) json_encode($payload)) > 500_000, 413, 'The cart is too large.');

        $query = PosActiveCart::where('company_id', current_company_id())->where('cashier_id', $request->user()->id);

        if ($payload['cart'] === []) {
            $query->delete();

            return response()->json(['data' => null]);
        }

        $cart = $query->first() ?? new PosActiveCart(['company_id' => current_company_id(), 'cashier_id' => $request->user()->id]);
        $cart->fill([
            'store_id' => $store->id,
            'payload' => $payload,
            'item_count' => count($payload['cart']),
            'gross_total' => round(collect($payload['cart'])->sum(fn (array $line) => (float) $line['qty'] * (float) $line['unit_price']), 2),
        ])->save();
        $cart->touch();

        return response()->json(['data' => ['saved_at' => $cart->updated_at->toIso8601String()]]);
    }

    /**
     * Pulihkan keranjang tersimpan saat POS dibuka (mis. setelah refresh). Setiap pemulihan dicatat di Log
     * Aktivitas; keranjang yang sudah kedaluwarsa dibuang dan dicatat.
     */
    public function restoreCart(Request $request): JsonResponse
    {
        $this->store($request) ?: abort(422, 'The cashier has not been assigned to a store.');

        $cart = PosActiveCart::where('company_id', current_company_id())->where('cashier_id', $request->user()->id)->first();

        if (! $cart) {
            return response()->json(['data' => null]);
        }

        $summary = "{$cart->item_count} barang, Rp ".number_format((float) $cart->gross_total, 0, ',', '.');
        $properties = ['item_count' => $cart->item_count, 'gross_total' => (float) $cart->gross_total, 'saved_at' => $cart->updated_at->toIso8601String()];

        if ($cart->isExpired()) {
            ActivityLogger::log('pos.cart_expired', "Keranjang POS tersimpan kedaluwarsa dibuang ({$summary})", null, $properties);
            $cart->delete();

            return response()->json(['data' => null]);
        }

        ActivityLogger::log('pos.cart_restored', "Keranjang POS dipulihkan setelah POS dibuka ulang ({$summary})", null, $properties);

        return response()->json(['data' => $cart->payload, 'saved_at' => $cart->updated_at->toIso8601String()]);
    }

    /**
     * Catat kejadian di keranjang: batal transaksi, hapus item, qty dikurangi, atau halaman POS ditinggalkan saat transaksi berjalan.
     */
    public function cartEvent(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

        $data = $request->validate([
            'event' => ['required', Rule::in(['cancelled', 'item_removed', 'qty_reduced', 'left_open', 'duplicate_tab'])],
            'item_count' => ['nullable', 'integer', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:60'],
            'name' => ['nullable', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'from_qty' => ['nullable', 'numeric', 'min:0'],
            'to_qty' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', current_company_id())],
            'reason' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
            'items' => ['nullable', 'array', 'max:300'],
            'items.*.sku' => ['nullable', 'string', 'max:60'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.line_total' => ['nullable', 'numeric', 'min:0'],
        ]);

        $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $item = trim(($data['sku'] ?? '').' '.($data['name'] ?? ''));

        [$action, $description] = match ($data['event']) {
            'cancelled' => ['pos.cart_cancelled', "Batal transaksi di {$store->code}: ".(int) ($data['item_count'] ?? 0).' barang, total '.$rupiah($data['total'] ?? 0)],
            'duplicate_tab' => ['pos.duplicate_tab_blocked', "Tab POS kedua diblokir di {$store->code}: POS sudah terbuka di tab lain pada browser yang sama"],
            'left_open' => ['pos.cart_left_open', "Halaman POS ditutup atau dimuat ulang saat transaksi berjalan di {$store->code}: ".(int) ($data['item_count'] ?? 0).' barang, nilai '.$rupiah($data['total'] ?? 0)],
            'item_removed' => ['pos.cart_item_removed', "Hapus item di {$store->code}: {$item} × ".($data['qty'] ?? 0).' ('.$rupiah($data['amount'] ?? 0).')'],
            default => ['pos.cart_qty_reduced', "Qty dikurangi di {$store->code}: {$item} dari ".($data['from_qty'] ?? 0).' menjadi '.($data['to_qty'] ?? 0)],
        };

        ActivityLogger::log($action, $description, null, collect($data)->except('items')->all() + ['store_id' => $store->id]);

        if ($data['event'] === 'cancelled') {
            PosCancellation::recordFor($request, PosCancellation::TYPE_CART, [
                'store_id' => $store->id,
                'customer_id' => $data['customer_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'items' => $data['items'] ?? null,
                'item_count' => (int) ($data['item_count'] ?? count($data['items'] ?? [])),
                'total' => $data['total'] ?? 0,
                'occurred_at' => $data['occurred_at'] ?? null,
            ]);

            PosActiveCart::where('company_id', current_company_id())->where('cashier_id', $request->user()->id)->delete();
        }

        return response()->json(['data' => ['logged' => true]], 201);
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
            'payments.*.approval_code' => ['nullable', 'string', 'max:50'],
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

        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

        abort_if(
            ($data['origin'] ?? 'online') !== 'offline' && $originStoreId && $originStoreId !== $store->id,
            409,
            'Your store assignment changed. Reload POS before continuing.'
        );

        $data['store_id'] = $store->id;

        // Bila EOD tanggal sebelumnya tertunda, transaksi dicatat pada tanggal itu (SaleService), asalkan
        // kasir sudah mengonfirmasinya saat login. EOD tanggal posting yang sudah ditutup tetap mengunci transaksi online.
        if (($data['origin'] ?? 'online') !== 'offline') {
            $calendarDate = company_business_date($data['sold_at'] ?? null);
            $postingDate = StoreClosure::postingDate($store->id, $calendarDate);

            abort_if(
                $postingDate !== $calendarDate && ! $this->backdateAcknowledged($store->id, $postingDate),
                409,
                "Confirmation of business date {$postingDate} is required. Reload POS."
            );
            abort_if(
                StoreClosure::isClosed($store->id, $postingDate),
                422,
                "EOD for {$postingDate} is closed. Reopen EOD in the Management Console to continue transacting."
            );
            abort_unless(
                StoreClosure::isOpen($store->id, $postingDate),
                422,
                "The store has not been opened for {$postingDate}. Open the store first."
            );
            abort_unless(
                ShiftService::hasActiveShift($request->user(), $store->id),
                422,
                'You have not started a shift. Start a shift first.'
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

        // Penjualan online selesai: keranjang tersimpan kasir ini tidak perlu dipulihkan lagi. Penjualan offline yang
        // baru disinkronkan tidak menyentuhnya karena keranjang aktif bisa jadi transaksi lain.
        if (($data['origin'] ?? 'online') === 'online') {
            PosActiveCart::where('company_id', current_company_id())->where('cashier_id', $request->user()->id)->delete();
        }

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

    /**
     * Daftar transaksi gerai kasir pada business date aktif (tanggal posting). Transaksi gerai atau tanggal lain
     * tidak ditampilkan; transaksi offline yang belum tersinkron hanya ada di perangkat dan digabung di client.
     */
    public function todaySales(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

        $postingDate = store_business_status($store->id)['posting_date'];

        $sales = Sale::with('customer')
            ->where('company_id', current_company_id())
            ->where('store_id', $store->id)
            ->whereDate('business_date', $postingDate)
            ->latest('sold_at')
            ->limit(200)
            ->get();

        return response()->json([
            'business_date' => $postingDate,
            'summary' => $this->daySummary($store, $postingDate),
            'data' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status,
                'origin' => $sale->origin,
                'sync_status' => $sale->sync_status,
                'customer' => $sale->customer ? ['code' => $sale->customer->code, 'name' => $sale->customer->name] : null,
                'total' => (float) $sale->total,
                'tax_total' => (float) $sale->tax_total,
                'sold_at' => $sale->sold_at?->toIso8601String(),
            ]),
        ]);
    }

    public function showSale(Request $request, Sale $sale): JsonResponse
    {
        $this->abortUnlessOwnStore($request, $sale);

        return response()->json(['data' => $this->salePayload($sale->load('lines', 'payments.paymentType', 'delivery'))]);
    }

    public function voidSale(Request $request, Sale $sale): JsonResponse
    {
        $this->abortUnlessOwnStore($request, $sale);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $sale = SaleService::void($sale, $request->user(), $data['reason']);

        ActivityLogger::log('pos.sale_void', "Void transaksi {$sale->number}", $sale);

        PosCancellation::recordFor($request, PosCancellation::TYPE_SALE_VOID, [
            'store_id' => $sale->store_id,
            'customer_id' => $sale->customer_id,
            'sale_id' => $sale->id,
            'sale_number' => $sale->number,
            'reason' => $data['reason'],
            'items' => $sale->lines->map(fn ($line) => [
                'sku' => $line->sku_snapshot,
                'name' => $line->name_snapshot,
                'qty' => $line->qty,
                'unit_price' => $line->unit_price,
                'discount' => $line->discount,
                'line_total' => $line->line_total,
            ])->all(),
            'total' => $sale->total,
        ]);

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
            return response()->json(['message' => 'Transaction not found.'], 404);
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
        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

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

        abort_if($sale->isVoid(), 422, 'A void transaction cannot be returned.');
        abort_if($sale->store_id !== $store->id, 422, 'Returns can only be made at the store where the sale was made.');

        if ($company->return_window_days && $sale->sold_at && $sale->sold_at->diffInDays(now()) > $company->return_window_days) {
            abort(422, 'The return is past the allowed time window.');
        }

        $usesWarehouse = SaleStock::usesWarehouseLedger($sale->id);

        $return = DB::transaction(function () use ($sale, $data, $store, $request, $usesWarehouse) {
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
                abort_if($qty > $remainingQty, 422, "Return quantity exceeds the remaining quantity for {$line->sku_snapshot}.");

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
                    $stockOptions = [
                        'company_id' => $sale->company_id,
                        'ref_type' => 'sales_return',
                        'ref_id' => $return->id,
                        'created_by' => $request->user()->id,
                    ];

                    // Penjualan yang mengurangi buku besar warehouse dikembalikan ke buku yang sama.
                    if ($usesWarehouse) {
                        SaleStock::record($sale->store_id, $line->product_id, StockLedger::TYPE_SALE_RETURN, $qty, $stockOptions);
                    } else {
                        StockService::record($sale->store_id, $line->product_id, StockLedger::TYPE_SALE_RETURN, $qty, $stockOptions);
                    }
                }
            }

            $return->update(['total' => round($total, 2)]);

            LoyaltyService::reverseForReturn($return);

            SapReturnPosting::queue($return);

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
        abort_unless($store, 422, 'The cashier has not been assigned to a store.');

        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'device_label' => ['nullable', 'string', 'max:100'],
            'payload' => ['required', 'array'],
        ]);

        $existing = SaleDraft::where('client_uuid', $data['client_uuid'])->first();

        abort_if(
            $existing && ($existing->company_id !== current_company_id() || $existing->cashier_id !== $request->user()->id),
            409,
            'The held transaction ID is already in use.'
        );
        abort_if($existing?->status === 'finalized', 409, 'The held transaction has already become a sale.');

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

        return response()->json(['message' => 'Held transaction deleted.']);
    }

    public function consumeHold(Request $request, SaleDraft $draft): JsonResponse
    {
        abort_unless($draft->company_id === current_company_id(), 404);
        abort_unless($draft->cashier_id === $request->user()->id, 403);
        abort_unless($draft->store_id === $this->store($request)?->id, 409, 'This held transaction belongs to another store.');

        $consumed = SaleDraft::whereKey($draft->id)->where('status', 'held')->update(['status' => 'resumed']);
        abort_if($consumed === 0, 409, 'The held transaction was already resumed or deleted.');

        return response()->json(['message' => 'Hold retrieved for resuming.', 'data' => [
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

        return response()->json(['message' => 'Transaction returned to the held list.']);
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

        abort_if(blank($data['phone'] ?? null) && blank($data['code'] ?? null), 422, 'A phone number or customer code is required.');

        $companyId = current_company_id();

        if (filled($data['phone'] ?? null)) {
            abort_if(Customer::where('company_id', $companyId)->where('phone', $data['phone'])->exists(), 422, 'This phone number is already registered.');
        }
        $sapActive = (bool) CompanyDetail::find($companyId)?->sapIntegrationActive();

        if ($sapActive) {
            // Kode customer = CardCode yang dibuat SAP B1 setelah customer terkirim.
            $data['code'] = null;
        } elseif (blank($data['code'] ?? null)) {
            $data['code'] = 'CUST-'.str_pad((string) (Customer::where('company_id', $companyId)->count() + 1), 5, '0', STR_PAD_LEFT);
        } else {
            abort_if(Customer::where('company_id', $companyId)->where('code', $data['code'])->exists(), 422, 'This customer code is already in use.');
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
            'group_code' => $c->customerGroup?->code,
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

    /**
     * Transaksi hanya boleh dibuka atau di-void dari gerai yang sedang dipakai, bukan gerai lain di perusahaan yang sama.
     */
    private function abortUnlessOwnStore(Request $request, Sale $sale): void
    {
        abort_unless(
            $sale->company_id === current_company_id() && $sale->store_id === $this->store($request)?->id,
            404,
        );
    }

    /**
     * Ringkasan satu business date gerai: transaksi selesai dijumlah, void dihitung terpisah.
     * Kembalian dikurangkan dari tunai (CASH) agar rincian metode bayar sama dengan total penjualan; metode tunai ditentukan dari jenisnya (kind), bukan kodenya.
     *
     * @return array{count: int, void_count: int, subtotal: float, discount_total: float, tax_total: float, total: float, by_payment_type: list<array{code: string, description: ?string, amount: float}>}
     */
    private function daySummary(Store $store, string $businessDate): array
    {
        $daySales = fn () => Sale::where('company_id', current_company_id())
            ->where('store_id', $store->id)
            ->whereDate('business_date', $businessDate);

        $completed = $daySales()->where('status', '!=', Sale::STATUS_VOID)
            ->selectRaw('count(*) as sale_count, coalesce(sum(subtotal), 0) as subtotal, coalesce(sum(discount_total), 0) as discount_total, coalesce(sum(tax_total), 0) as tax_total, coalesce(sum(total), 0) as total, coalesce(sum(change_total), 0) as change_total')
            ->first();

        $byType = DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->leftJoin('payment_types', 'payment_types.id', '=', 'sale_payments.payment_type_id')
            ->where('sales.company_id', current_company_id())
            ->where('sales.store_id', $store->id)
            ->whereDate('sales.business_date', $businessDate)
            ->where('sales.status', '!=', Sale::STATUS_VOID)
            ->where('sale_payments.status', 'confirmed')
            ->groupBy('payment_types.code', 'payment_types.description', 'payment_types.kind')
            ->orderBy('payment_types.code')
            ->selectRaw('payment_types.code as code, payment_types.description as description, payment_types.kind as kind, sum(sale_payments.amount) as amount')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code ?? '-',
                'description' => $row->description,
                'amount' => round((float) $row->amount - ($row->kind === PaymentType::KIND_CASH ? (float) $completed->change_total : 0), 2),
            ])
            ->values()
            ->all();

        return [
            'count' => (int) $completed->sale_count,
            'void_count' => $daySales()->where('status', Sale::STATUS_VOID)->count(),
            'subtotal' => (float) $completed->subtotal,
            'discount_total' => (float) $completed->discount_total,
            'tax_total' => (float) $completed->tax_total,
            'total' => (float) $completed->total,
            'by_payment_type' => $byType,
        ];
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
                'approval_code' => $p->approval_code,
                'type' => $p->relationLoaded('paymentType') && $p->paymentType
                    ? ['code' => $p->paymentType->code, 'description' => $p->paymentType->description]
                    : null,
            ]),
            'delivery' => $sale->delivery ? [
                'recipient_name' => $sale->delivery->recipient_name,
                'address' => $sale->delivery->address,
                'status' => $sale->delivery->status,
            ] : null,
        ];
    }
}
