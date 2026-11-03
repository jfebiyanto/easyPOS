<?php

namespace App\Support;

use App\Models\Promotion;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Perhitungan promosi per baris keranjang (tanpa menyimpan apa pun).
 *
 * Aturan:
 * - Satu promosi per baris; dipilih potongan terbesar (seri: promosi khusus produk, lalu kode terkecil).
 * - Harga dasar adalah harga efektif dari price list; harga khusus hanya berlaku bila lebih murah.
 * - Diskon nominal & harga khusus berlaku per item; min_qty dicek terhadap qty baris.
 * - Beli X Gratis Y berlaku kelipatan (dibatasi max_applications). Produk sama: dari setiap X+Y unit,
 *   Y unit gratis. Produk bonus berbeda: diskon dicatat di baris produk bonus sebanyak jatah bonus.
 */
class PromotionService
{
    /**
     * @return Collection<int, Promotion>
     */
    public static function activeFor(string $companyId, CarbonInterface $at): Collection
    {
        return Promotion::where('company_id', $companyId)->activeAt($at)->get();
    }

    /**
     * @param  array<int|string, array{product_id: string, qty: float|int|string, unit_price: float|int|string}>  $lines
     * @param  iterable<Promotion>  $promotions
     * @return array{
     *     lines: array<int|string, array{promotion_id: ?string, promotion_name: ?string, promotion_discount: float}>,
     *     bonus_hints: list<array{promotion_id: string, promotion_name: string, product_id: string, qty: int}>
     * }
     */
    public static function apply(array $lines, iterable $promotions): array
    {
        $promotions = collect($promotions)->sortBy([
            fn (Promotion $a, Promotion $b) => ($a->product_id === null) <=> ($b->product_id === null),
            fn (Promotion $a, Promotion $b) => strcmp($a->code, $b->code),
        ])->values();

        $qtyByProduct = [];
        foreach ($lines as $line) {
            $qtyByProduct[$line['product_id']] = ($qtyByProduct[$line['product_id']] ?? 0) + (float) $line['qty'];
        }

        $candidates = [];
        $bonusHints = [];

        foreach ($promotions as $promotion) {
            if ($promotion->type === 'buy_x_get_y') {
                $hint = self::collectBuyXGetY($promotion, $lines, $qtyByProduct, $candidates);
                if ($hint) {
                    $bonusHints[] = $hint;
                }

                continue;
            }

            foreach ($lines as $key => $line) {
                $discount = self::itemDiscount($promotion, $line);
                if ($discount > 0) {
                    $candidates[$key][] = [$promotion, $discount];
                }
            }
        }

        $result = [];
        foreach ($lines as $key => $line) {
            $best = null;
            foreach ($candidates[$key] ?? [] as $candidate) {
                if ($best === null || $candidate[1] > $best[1]) {
                    $best = $candidate;
                }
            }

            $result[$key] = [
                'promotion_id' => $best ? $best[0]->id : null,
                'promotion_name' => $best ? $best[0]->name : null,
                'promotion_discount' => $best ? $best[1] : 0.0,
            ];
        }

        return ['lines' => $result, 'bonus_hints' => $bonusHints];
    }

    /**
     * Diskon persen, nominal per item, atau harga khusus untuk satu baris.
     *
     * @param  array{product_id: string, qty: float|int|string, unit_price: float|int|string}  $line
     */
    private static function itemDiscount(Promotion $promotion, array $line): float
    {
        $qty = (float) $line['qty'];
        $unitPrice = (float) $line['unit_price'];
        $value = (float) $promotion->value;

        if ($promotion->product_id !== null && $promotion->product_id !== $line['product_id']) {
            return 0.0;
        }

        if ($qty < max(1, (int) $promotion->min_qty)) {
            return 0.0;
        }

        $discount = match ($promotion->type) {
            'discount_percent' => $unitPrice * $qty * min($value, 100) / 100,
            'discount_amount' => min($value, $unitPrice) * $qty,
            'special_price' => max(0, $unitPrice - $value) * $qty,
            default => 0.0,
        };

        return round($discount, 2);
    }

    /**
     * Daftarkan kandidat diskon Beli X Gratis Y dan kembalikan petunjuk bonus yang belum diambil.
     *
     * @param  array<int|string, array{product_id: string, qty: float|int|string, unit_price: float|int|string}>  $lines
     * @param  array<string, float>  $qtyByProduct
     * @param  array<int|string, list<array{0: Promotion, 1: float}>>  $candidates
     * @return array{promotion_id: string, promotion_name: string, product_id: string, qty: int}|null
     */
    private static function collectBuyXGetY(Promotion $promotion, array $lines, array $qtyByProduct, array &$candidates): ?array
    {
        $buyQty = (int) $promotion->buy_qty;
        $getQty = (int) $promotion->get_qty;
        $buyProductId = $promotion->product_id;

        if ($buyQty < 1 || $getQty < 1 || $buyProductId === null) {
            return null;
        }

        $bonusProductId = $promotion->get_product_id ?? $buyProductId;
        $boughtUnits = (int) floor($qtyByProduct[$buyProductId] ?? 0);
        $limit = $promotion->max_applications ?: PHP_INT_MAX;

        if ($bonusProductId === $buyProductId) {
            $applications = min(intdiv($boughtUnits, $buyQty + $getQty), $limit);
            $freeUnits = $applications * $getQty;

            $remainder = $boughtUnits - $applications * ($buyQty + $getQty);
            $missing = $applications < $limit && $remainder >= $buyQty ? $buyQty + $getQty - $remainder : 0;
        } else {
            $applications = min(intdiv($boughtUnits, $buyQty), $limit);
            $freeUnits = $applications * $getQty;

            $bonusUnits = (int) floor($qtyByProduct[$bonusProductId] ?? 0);
            $missing = max(0, $freeUnits - $bonusUnits);
        }

        foreach ($lines as $key => $line) {
            if ($freeUnits <= 0) {
                break;
            }
            if ($line['product_id'] !== $bonusProductId) {
                continue;
            }

            $units = min($freeUnits, (int) floor((float) $line['qty']));
            if ($units > 0) {
                $candidates[$key][] = [$promotion, round($units * (float) $line['unit_price'], 2)];
                $freeUnits -= $units;
            }
        }

        if ($missing <= 0) {
            return null;
        }

        return [
            'promotion_id' => $promotion->id,
            'promotion_name' => $promotion->name,
            'product_id' => $bonusProductId,
            'qty' => $missing,
        ];
    }
}
