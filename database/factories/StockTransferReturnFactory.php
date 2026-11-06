<?php

namespace Database\Factories;

use App\Models\StockTransferReturn;
use App\Models\StockTransferShipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferReturn>
 */
class StockTransferReturnFactory extends Factory
{
    /**
     * Default: Return untuk Shipment posted baru, atas nama store pengirimnya.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shipment = StockTransferShipment::factory()->posted()->create();

        return [
            'company_id' => $shipment->company_id,
            'shipment_id' => $shipment->id,
            'store_id' => $shipment->from_store_id,
            'number' => 'STRT-'.fake()->unique()->numerify('######'),
            'note' => null,
            'returned_at' => now(),
            'sap_post_status' => StockTransferReturn::SAP_PENDING,
        ];
    }
}
