<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Support\PromotionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kasus diambil dari tests/fixtures/promotion-cases.json yang juga dipakai tes Vitest
 * resources/js/pos/promotions.test.js, agar hitungan server dan kasir selalu sama.
 */
class PromotionServiceTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $promotions
     * @param  list<array{product_id: string, qty: int|float, unit_price: int|float}>  $lines
     * @param  array{lines: list<array<string, mixed>>, bonus_hints: list<array<string, mixed>>}  $expected
     */
    #[DataProvider('promotionCases')]
    public function test_perhitungan_promosi(array $promotions, array $lines, array $expected, array $context = [], ?array $hierarchy = null): void
    {
        $result = PromotionService::apply(
            $lines,
            array_map(fn (array $attributes) => (new Promotion)->forceFill($attributes), $promotions),
            $context,
            $hierarchy,
        );

        $expectedLines = array_map(
            fn (array $line) => array_replace($line, ['promotion_discount' => (float) $line['promotion_discount']]),
            $expected['lines'],
        );

        $this->assertSame($expectedLines, $result['lines']);
        $this->assertSame($expected['bonus_hints'], $result['bonus_hints']);
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<string, mixed>}>
     */
    public static function promotionCases(): array
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../fixtures/promotion-cases.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($cases)
            ->mapWithKeys(fn (array $case) => [$case['name'] => [$case['promotions'], $case['lines'], $case['expected'], $case['context'] ?? [], $case['hierarchy'] ?? null]])
            ->all();
    }
}
