<?php

namespace Database\Factories;

use App\Models\StockTransferReceipt;
use App\Models\StockTransferShipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferReceipt>
 */
class StockTransferReceiptFactory extends Factory
{
    /**
     * Default: Receipt untuk Shipment posted baru, atas nama store penerimanya.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shipment = StockTransferShipment::factory()->posted()->create();

        return [
            'company_id' => $shipment->company_id,
            'shipment_id' => $shipment->id,
            'store_id' => $shipment->to_store_id,
            'number' => 'STR-'.fake()->unique()->numerify('######'),
            'note' => null,
            'received_at' => now(),
            'sap_post_status' => StockTransferReceipt::SAP_PENDING,
        ];
    }
}
