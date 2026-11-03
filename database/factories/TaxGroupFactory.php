<?php

namespace Database\Factories;

use App\Models\TaxGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxGroup>
 */
class TaxGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PPN-##')),
            'name' => 'PPN Keluaran',
            'rate' => 11,
            'source' => TaxGroup::SOURCE_MANUAL,
            'is_active' => true,
        ];
    }

    public function fromSap(): static
    {
        return $this->state(['source' => TaxGroup::SOURCE_SAP]);
    }
}
