<?php

namespace App\Support;

use App\Models\Promotion;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Perhitungan promosi per baris keranjang (tanpa menyimpan apa pun).
 *
 * Aturan:
 * - Satu promosi per baris. Tanpa hierarki: potongan terbesar menang (seri: promosi khusus produk, lalu
 *   kode terkecil). Dengan hierarki (urutan sumber diskon dari pengaturan perusahaan): sumber paling atas
 *   yang menghasilkan diskon pada baris itu menang; dalam sumber yang sama potongan terbesar menang.
 * - Baris Discount Group SAP satu grup yang cocok pada satu baris digabung dulu menurut relasi grup
 *   (terendah, tertinggi, rata-rata, jumlah, berkalian) menjadi satu kandidat.
 * - Promosi dapat dibatasi ke produk, brand, item group, pelanggan (semua, customer group, atau satu
 *   pelanggan), dan price list gerai. Harga khusus bertingkat (volume_price) memakai total qty barang
 *   yang sama di seluruh baris.
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
     * @param  array<int|string, array{product_id: string, qty: float|int|string, unit_price: float|int|string, brand_id?: ?string, product_group_id?: ?string}>  $lines
     * @param  iterable<Promotion>  $promotions
     * @param  array{customer?: ?array{code?: ?string, group_code?: ?string}, price_list_code?: ?string}  $context
     * @param  list<string>|null  $hierarchy  urutan kunci sumber diskon; null = terbesar menang
     * @return array{
     *     lines: array<int|string, array{promotion_id: ?string, promotion_name: ?string, promotion_discount: float}>,
     *     bonus_hints: list<array{promotion_id: string, promotion_name: string, product_id: string, qty: int}>
     * }
     */
    public static function apply(array $lines, iterable $promotions, array $context = [], ?array $hierarchy = null): array
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
            if (! self::appliesToContext($promotion, $context)) {
                continue;
            }

            if ($promotion->type === 'buy_x_get_y') {
                $hint = self::collectBuyXGetY($promotion, $lines, $qtyByProduct, $candidates);
                if ($hint) {
                    $bonusHints[] = $hint;
                }

                continue;
            }

            foreach ($lines as $key => $line) {
                $discount = self::itemDiscount($promotion, $line, $qtyByProduct);
                if ($discount > 0) {
                    $candidates[$key][] = [$promotion, $discount];
                }
            }
        }

        $result = [];
        foreach ($lines as $key => $line) {
            $best = self::pickBest($candidates[$key] ?? [], $line, $hierarchy);

            $result[$key] = [
                'promotion_id' => $best ? $best[0]->id : null,
                'promotion_name' => $best ? $best[0]->name : null,
                'promotion_discount' => $best ? $best[1] : 0.0,
            ];
        }

        return ['lines' => $result, 'bonus_hints' => $bonusHints];
    }

    /**
     * Pembatas pelanggan dan price list: promosi di luar konteks transaksi tidak ikut dihitung.
     *
     * @param  array{customer?: ?array{code?: ?string, group_code?: ?string}, price_list_code?: ?string}  $context
     */
    private static function appliesToContext(Promotion $promotion, array $context): bool
    {
        if ($promotion->price_list_code !== null && (string) $promotion->price_list_code !== (string) ($context['price_list_code'] ?? '')) {
            return false;
        }

        $customer = $context['customer'] ?? null;

        return match ($promotion->customer_scope ?? Promotion::CUSTOMER_ALL) {
            Promotion::CUSTOMER_GROUP => $customer !== null && (string) ($customer['group_code'] ?? '') === (string) $promotion->customer_ref,
            Promotion::CUSTOMER_SPECIFIC => $customer !== null && (string) ($customer['code'] ?? '') === (string) $promotion->customer_ref,
            default => true,
        };
    }

    /**
     * Diskon persen, nominal per item, harga khusus, atau harga khusus bertingkat untuk satu baris.
     *
     * @param  array{product_id: string, qty: float|int|string, unit_price: float|int|string, brand_id?: ?string, product_group_id?: ?string}  $line
     * @param  array<string, float>  $qtyByProduct
     */
    private static function itemDiscount(Promotion $promotion, array $line, array $qtyByProduct): float
    {
        $qty = (float) $line['qty'];
        $unitPrice = (float) $line['unit_price'];
        $value = (float) $promotion->value;

        if ($promotion->product_id !== null && $promotion->product_id !== $line['product_id']) {
            return 0.0;
        }

        if ($promotion->brand_id !== null && $promotion->brand_id !== ($line['brand_id'] ?? null)) {
            return 0.0;
        }

        if ($promotion->product_group_id !== null && $promotion->product_group_id !== ($line['product_group_id'] ?? null)) {
            return 0.0;
        }

        if ($qty < max(1, (int) $promotion->min_qty)) {
            return 0.0;
        }

        $discount = match ($promotion->type) {
            'discount_percent' => $unitPrice * $qty * min($value, 100) / 100,
            'discount_amount' => min($value, $unitPrice) * $qty,
            'special_price' => max(0, $unitPrice - $value) * $qty,
            Promotion::TYPE_VOLUME_PRICE => self::volumeDiscount($promotion, $unitPrice, $qty, $qtyByProduct[$line['product_id']] ?? $qty),
            default => 0.0,
        };

        return round($discount, 2);
    }

    /**
     * Harga bertingkat: tingkat tertinggi yang min qty-nya terpenuhi oleh total qty barang yang sama.
     */
    private static function volumeDiscount(Promotion $promotion, float $unitPrice, float $lineQty, float $totalQty): float
    {
        $tier = collect($promotion->tiers ?? [])
            ->filter(fn (array $tier) => (float) $tier['min_qty'] <= $totalQty)
            ->sortBy(fn (array $tier) => (float) $tier['min_qty'])
            ->last();

        return $tier === null ? 0.0 : max(0, $unitPrice - (float) $tier['price']) * $lineQty;
    }

    /**
     * Pilih kandidat pemenang satu baris menurut hierarki (atau terbesar menang bila tanpa hierarki).
     *
     * @param  list<array{0: Promotion, 1: float}>  $candidates
     * @param  array{qty: float|int|string, unit_price: float|int|string}  $line
     * @param  list<string>|null  $hierarchy
     * @return array{0: Promotion, 1: float}|null
     */
    private static function pickBest(array $candidates, array $line, ?array $hierarchy): ?array
    {
        $best = null;
        $bestRank = PHP_INT_MAX;

        foreach (self::combineGroups($candidates, $line) as $candidate) {
            $rank = 0;
            if ($hierarchy !== null) {
                $position = array_search($candidate[0]->hierarchyKey(), $hierarchy, true);
                $rank = $position === false ? count($hierarchy) : $position;
            }

            if ($best === null || $rank < $bestRank || ($rank === $bestRank && $candidate[1] > $best[1])) {
                $best = $candidate;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * Baris Discount Group SAP satu grup pada satu baris digabung menurut relasi grup menjadi satu kandidat
     * (diwakili baris pertama menurut urutan kode). Kandidat lain tidak berubah.
     *
     * @param  list<array{0: Promotion, 1: float}>  $candidates
     * @param  array{qty: float|int|string, unit_price: float|int|string}  $line
     * @return list<array{0: Promotion, 1: float}>
     */
    private static function combineGroups(array $candidates, array $line): array
    {
        $result = [];
        $groups = [];

        foreach ($candidates as $candidate) {
            if ($candidate[0]->group_key === null) {
                $result[] = $candidate;

                continue;
            }

            $groups[$candidate[0]->group_key][] = $candidate;
        }

        foreach ($groups as $members) {
            if (count($members) === 1) {
                $result[] = $members[0];

                continue;
            }

            $percents = array_map(fn (array $member) => min((float) $member[0]->value, 100), $members);
            $combined = match ($members[0][0]->group_relation) {
                'highest' => max($percents),
                'average' => array_sum($percents) / count($percents),
                'total' => min(100, array_sum($percents)),
                'multiplied' => 100 * (1 - array_reduce($percents, fn (float $carry, float $p) => $carry * (1 - $p / 100), 1.0)),
                default => min($percents),
            };

            $gross = (float) $line['unit_price'] * (float) $line['qty'];
            $result[] = [$members[0][0], round($gross * $combined / 100, 2)];
        }

        return $result;
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
