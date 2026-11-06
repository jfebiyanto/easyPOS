<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('WH-###')),
            'name' => fake()->words(2, true),
            'profit_center' => null,
            'is_retail' => true,
            'is_active' => true,
        ];
    }

    /**
     * Warehouse non-retail (mis. gudang pusat): tidak dapat dipakai gerai.
     */
    public function nonRetail(): static
    {
        return $this->state(fn (array $attributes) => ['is_retail' => false]);
    }

    /**
     * Gudang in-transit: selalu non-retail.
     */
    public function transit(): static
    {
        return $this->state(fn (array $attributes) => ['is_retail' => false, 'is_transit' => true]);
    }
}
