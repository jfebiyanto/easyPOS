<?php

namespace Database\Factories;

use App\Models\StockTransferReturn;
use App\Models\StockTransferReturnLine;
use App\Models\StockTransferShipmentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferReturnLine>
 */
class StockTransferReturnLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'return_id' => StockTransferReturn::factory(),
            'shipment_line_id' => fn (array $attributes) => StockTransferShipmentLine::factory()->create([
                'shipment_id' => StockTransferReturn::findOrFail($attributes['return_id'])->shipment_id,
            ])->id,
            'qty' => 1,
        ];
    }
}
