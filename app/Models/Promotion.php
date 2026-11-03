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
    ];

    public const TYPES = [
        'discount_percent' => 'Diskon Persen (%)',
        'discount_amount' => 'Diskon Nominal (Rp)',
        'special_price' => 'Harga Khusus',
        'buy_x_get_y' => 'Beli X Gratis Y',
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
        ];
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
     * Ringkasan aturan untuk daftar promosi, mis. "Beli 2 Gratis 1 Cookies · maks. 3×".
     */
    public function ruleSummary(): string
    {
        $summary = match ($this->type) {
            'discount_percent' => 'Diskon '.rtrim(rtrim((string) $this->value, '0'), '.').'%',
            'discount_amount' => 'Potongan Rp '.number_format((float) $this->value, 0, ',', '.').' /item',
            'special_price' => 'Harga Rp '.number_format((float) $this->value, 0, ',', '.'),
            'buy_x_get_y' => "Beli {$this->buy_qty} Gratis {$this->get_qty}".($this->bonusProduct ? " {$this->bonusProduct->name}" : ''),
            default => (string) $this->value,
        };

        if ($this->type === 'buy_x_get_y') {
            return $this->max_applications ? "{$summary} · maks. {$this->max_applications}×" : $summary;
        }

        return $this->min_qty > 1 ? "{$summary} · min. {$this->min_qty}" : $summary;
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
