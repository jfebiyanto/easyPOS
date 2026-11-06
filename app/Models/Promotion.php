<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class Promotion extends MasterModel
{
    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'value', 'product_id', 'min_qty',
        'buy_qty', 'get_qty', 'get_product_id', 'max_applications',
        'starts_at', 'ends_at', 'is_active', 'description',
        'source', 'sap_ref', 'brand_id', 'product_group_id', 'customer_scope', 'customer_ref',
        'price_list_code', 'tiers', 'group_key', 'group_relation',
    ];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SAP = 'sap';

    /** Harga khusus bertingkat menurut qty (Period & Volume Discount SAP B1). */
    public const TYPE_VOLUME_PRICE = 'volume_price';

    public const CUSTOMER_ALL = 'all';

    public const CUSTOMER_GROUP = 'customer_group';

    public const CUSTOMER_SPECIFIC = 'customer';

    /**
     * Sumber diskon yang diurutkan di hierarki promosi (urutan bawaan = urutan di sini).
     */
    public const HIERARCHY_SOURCES = [
        'sap_period_volume' => 'Period & Volume Discount (SAP B1)',
        'sap_discount_group' => 'Discount Group (SAP B1)',
        'special_price' => 'Special Price (easyPOS)',
        'discount_amount' => 'Fixed Discount (easyPOS)',
        'buy_x_get_y' => 'Buy X Get Y (easyPOS)',
    ];

    /**
     * Tipe yang dapat dibuat manual di easyPOS. Diskon persen dan harga bertingkat berasal dari SAP B1
     * (Discount Group dan Period & Volume Discount), jadi tidak dibuat manual.
     */
    public const TYPES = [
        'discount_amount' => 'Fixed Discount (Rp)',
        'special_price' => 'Special Price',
        'buy_x_get_y' => 'Buy X Get Y',
    ];

    /** Label semua tipe untuk tampilan, termasuk tipe sumber SAP. */
    public const TYPE_LABELS = [
        'discount_percent' => 'Percentage Discount (%)',
        'discount_amount' => 'Fixed Discount (Rp)',
        'special_price' => 'Special Price',
        'buy_x_get_y' => 'Buy X Get Y',
        self::TYPE_VOLUME_PRICE => 'Tiered Price',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'value' => 'decimal:2',
            'min_qty' => 'integer',
            'buy_qty' => 'integer',
            'get_qty' => 'integer',
            'max_applications' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'tiers' => 'array',
        ];
    }

    public function isFromSap(): bool
    {
        return $this->source === self::SOURCE_SAP;
    }

    /**
     * Kunci sumber di hierarki promosi untuk promosi ini.
     */
    public function hierarchyKey(): string
    {
        if ($this->isFromSap()) {
            return $this->type === self::TYPE_VOLUME_PRICE ? 'sap_period_volume' : 'sap_discount_group';
        }

        return $this->type;
    }

    /**
     * Aturan validasi bersama untuk form admin dan API; field yang wajib bergantung pada tipe.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?string $companyId, ?string $ignoreId, mixed $type): array
    {
        $productExists = Rule::exists('products', 'id')->where('company_id', $companyId);
        $isBuyXGetY = $type === 'buy_x_get_y';

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('promotions', 'code')->where('company_id', $companyId)->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'value' => match ($type) {
                'discount_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
                'discount_amount' => ['required', 'numeric', 'gt:0'],
                'special_price' => ['required', 'numeric', 'min:0'],
                default => ['nullable', 'numeric', 'min:0'],
            },
            'product_id' => [in_array($type, ['special_price', 'buy_x_get_y'], true) ? 'required' : 'nullable', $productExists],
            'min_qty' => ['nullable', 'integer', 'min:1'],
            'buy_qty' => [$isBuyXGetY ? 'required' : 'nullable', 'integer', 'min:1'],
            'get_qty' => [$isBuyXGetY ? 'required' : 'nullable', 'integer', 'min:1'],
            'get_product_id' => ['nullable', $productExists],
            'max_applications' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Kosongkan field yang tidak dipakai tipe terpilih agar sisa nilai tipe lama tidak ikut terhitung.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if ($data['type'] === 'buy_x_get_y') {
            return array_merge($data, ['value' => 0, 'min_qty' => 1]);
        }

        return array_merge($data, [
            'min_qty' => $data['min_qty'] ?? 1,
            'buy_qty' => null,
            'get_qty' => null,
            'get_product_id' => null,
            'max_applications' => null,
        ]);
    }

    /**
     * Ringkasan aturan untuk daftar promosi, mis. "Buy 2 Get 1 Free Cookies · max. 3×".
     */
    public function ruleSummary(): string
    {
        $summary = match ($this->type) {
            'discount_percent' => 'Discount '.rtrim(rtrim((string) $this->value, '0'), '.').'%',
            self::TYPE_VOLUME_PRICE => 'Tiered: '.collect($this->tiers ?? [])->filter(fn (array $t) => $t['min_qty'] > 1)->map(fn (array $t) => '≥'.rtrim(rtrim((string) $t['min_qty'], '0'), '.').' → Rp '.number_format((float) $t['price'], 0, ',', '.'))->implode(', '),
            'discount_amount' => 'Discount Rp '.number_format((float) $this->value, 0, ',', '.').' /item',
            'special_price' => 'Price Rp '.number_format((float) $this->value, 0, ',', '.'),
            'buy_x_get_y' => "Buy {$this->buy_qty} Get {$this->get_qty} Free".($this->bonusProduct ? " {$this->bonusProduct->name}" : ''),
            default => (string) $this->value,
        };

        if ($this->type === 'buy_x_get_y') {
            return $this->max_applications ? "{$summary} · max. {$this->max_applications}×" : $summary;
        }

        return $this->min_qty > 1 ? "{$summary} · min. {$this->min_qty}" : $summary;
    }

    /**
     * Sasaran promosi untuk daftar: produk/brand/item group, dan pembatas pelanggan atau price list bila ada.
     */
    public function scopeLabel(): string
    {
        $target = $this->product?->name
            ?? ($this->brand ? 'Brand '.$this->brand->name : null)
            ?? ($this->productGroup ? 'Item group '.$this->productGroup->name : null)
            ?? 'All products';

        $limits = array_filter([
            match ($this->customer_scope) {
                self::CUSTOMER_GROUP => 'customer group '.$this->customer_ref,
                self::CUSTOMER_SPECIFIC => 'customer '.$this->customer_ref,
                default => null,
            },
            $this->price_list_code !== null ? 'price list '.$this->price_list_code : null,
        ]);

        return $limits === [] ? $target : $target.' · '.implode(', ', $limits);
    }

    /**
     * Periode untuk daftar. Diskon SAP disimpan sebagai waktu UTC dari hari kalender SAP (zona waktu perusahaan),
     * jadi ditampilkan dalam zona waktu perusahaan; promosi manual ditampilkan apa adanya.
     */
    public function periodLabel(): string
    {
        $timezone = $this->isFromSap() ? (CompanyDetail::current()->timezone ?: 'Asia/Jakarta') : null;
        $format = fn (?CarbonInterface $date, string $empty) => $date ? ($timezone ? $date->copy()->timezone($timezone) : $date)->format('d/m/Y') : $empty;

        return $format($this->starts_at, '-').' ~ '.$format($this->ends_at, '∞');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function productGroup()
    {
        return $this->belongsTo(ProductGroup::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Produk bonus Beli X Gratis Y; null berarti bonus berupa produk yang sama.
     */
    public function bonusProduct()
    {
        return $this->belongsTo(Product::class, 'get_product_id');
    }

    /**
     * Promosi aktif yang periodenya mencakup waktu transaksi (batas kosong = tanpa batas).
     */
    public function scopeActiveAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }
}
