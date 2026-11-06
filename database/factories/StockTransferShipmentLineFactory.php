<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockTransferShipment;
use App\Models\StockTransferShipmentLine;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferShipmentLine>
 */
class StockTransferShipmentLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipment_id' => StockTransferShipment::factory(),
            'product_id' => fn (array $attributes) => $this->makeProduct(
                StockTransferShipment::findOrFail($attributes['shipment_id'])->company_id
            )->id,
            'qty' => 10,
            'qty_received' => 0,
        ];
    }

    private function makeProduct(string $companyId): Product
    {
        $unit = Unit::create(['company_id' => $companyId, 'code' => 'U'.fake()->unique()->numerify('###'), 'name' => 'Pieces', 'is_active' => true]);
        $group = ProductGroup::create(['company_id' => $companyId, 'code' => 'G'.fake()->unique()->numerify('###'), 'name' => 'Group', 'is_active' => true]);

        return Product::create([
            'company_id' => $companyId,
            'product_group_id' => $group->id,
            'unit_id' => $unit->id,
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'name' => fake()->words(2, true),
            'base_price' => 1000,
            'track_stock' => true,
            'is_active' => true,
        ]);
    }
}
