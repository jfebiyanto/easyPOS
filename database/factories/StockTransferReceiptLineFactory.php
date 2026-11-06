<?php

namespace Database\Factories;

use App\Models\StockTransferReceipt;
use App\Models\StockTransferReceiptLine;
use App\Models\StockTransferShipmentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferReceiptLine>
 */
class StockTransferReceiptLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'receipt_id' => StockTransferReceipt::factory(),
            'shipment_line_id' => fn (array $attributes) => StockTransferShipmentLine::factory()->create([
                'shipment_id' => StockTransferReceipt::findOrFail($attributes['receipt_id'])->shipment_id,
            ])->id,
            'qty' => 1,
        ];
    }
}
