<?php

namespace App\Models;

use App\Support\UserAgentLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Jejak pembatalan transaksi (batal sebelum bayar dan void penjualan) untuk laporan pengawasan, lengkap dengan
 * gerai, kasir, pelanggan, isi barang, waktu, serta IP dan perangkat yang dipakai.
 */
class PosCancellation extends MasterModel
{
    public const TYPE_CART = 'cart';

    public const TYPE_SALE_VOID = 'sale_void';

    public const TYPE_LABELS = [
        self::TYPE_CART => 'Cancelled before payment',
        self::TYPE_SALE_VOID => 'Sale void',
    ];

    /** Kejadian yang dicatat lebih dari ini setelah terjadinya (mis. dari antrean offline) ditandai terlambat. */
    public const LATE_AFTER_SECONDS = 120;

    /** Kejadian offline yang lebih tua dari ini tidak dipercaya waktunya dan dicatat memakai waktu server. */
    public const MAX_OFFLINE_AGE_DAYS = 7;

    protected $fillable = [
        'company_id', 'type', 'store_id', 'store_label', 'cashier_id', 'cashier_name', 'customer_id', 'customer_label',
        'sale_id', 'sale_number', 'reason', 'item_count', 'total', 'items', 'occurred_at',
        'ip_address', 'user_agent', 'browser', 'pos_session_id',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total' => 'decimal:2',
            'item_count' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function isLate(): bool
    {
        return $this->created_at->diffInSeconds($this->occurred_at, false) < -self::LATE_AFTER_SECONDS;
    }

    /**
     * Catat pembatalan dari request ini: gerai, kasir, pelanggan, barang, waktu, IP, dan perangkat disalin.
     *
     * @param  array{store_id?: ?string, customer_id?: ?string, sale_id?: ?string, sale_number?: ?string, reason?: ?string, items?: ?array<int, array<string, mixed>>, item_count?: ?int, total?: float|int|string|null, occurred_at?: mixed}  $data
     */
    public static function recordFor(Request $request, string $type, array $data): self
    {
        $user = $request->user();
        $store = Store::find($data['store_id'] ?? $user->store_id);
        $customerId = $data['customer_id'] ?? $store?->default_customer_id;
        $customer = $customerId ? Customer::find($customerId) : null;
        $items = collect($data['items'] ?? [])->map(fn (array $item) => [
            'sku' => $item['sku'] ?? null,
            'name' => $item['name'] ?? null,
            'qty' => (float) ($item['qty'] ?? 0),
            'unit_price' => (float) ($item['unit_price'] ?? 0),
            'discount' => (float) ($item['discount'] ?? 0),
            'line_total' => (float) ($item['line_total'] ?? 0),
        ])->values()->all();

        $session = PosSession::activeFor($user);

        return static::create([
            'company_id' => $user->company_id,
            'type' => $type,
            'store_id' => $store?->id,
            'store_label' => $store ? $store->code.' · '.$store->name : null,
            'cashier_id' => $user->id,
            'cashier_name' => $user->name,
            'customer_id' => $customer?->id,
            'customer_label' => $customer ? trim(($customer->code ? $customer->code.' · ' : '').$customer->name) : null,
            'sale_id' => $data['sale_id'] ?? null,
            'sale_number' => $data['sale_number'] ?? null,
            'reason' => $data['reason'] ?? null,
            'item_count' => $data['item_count'] ?? count($items),
            'total' => $data['total'] ?? 0,
            'items' => $items === [] ? null : $items,
            'occurred_at' => self::trustedTime($data['occurred_at'] ?? null),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'browser' => UserAgentLabel::describe($request->userAgent()),
            'pos_session_id' => $session?->session_hash === PosSession::hashFor($request) ? $session->id : null,
        ]);
    }

    /**
     * Waktu kejadian dari kasir (mis. pembatalan yang tertahan saat offline) dipakai selama masuk akal;
     * waktu di masa depan atau terlalu lama dibuang dan diganti waktu server.
     */
    private static function trustedTime(mixed $claimed): Carbon
    {
        if (! $claimed) {
            return now();
        }

        $time = Carbon::parse($claimed);

        return $time->isFuture() || $time->lt(now()->subDays(self::MAX_OFFLINE_AGE_DAYS)) ? now() : $time;
    }

    /**
     * Isi barang sebagai teks (satu baris per barang) untuk ekspor.
     */
    public function itemsText(): string
    {
        return collect($this->items ?? [])
            ->map(fn (array $i) => rtrim(rtrim(number_format($i['qty'], 3, ',', '.'), '0'), ',').' × '.($i['name'] ?? '-').($i['sku'] ? " ({$i['sku']})" : '').' = Rp '.number_format($i['line_total'], 0, ',', '.'))
            ->implode("\n");
    }
}
