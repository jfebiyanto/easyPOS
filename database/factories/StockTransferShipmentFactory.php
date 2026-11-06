<?php

namespace Database\Factories;

use App\Models\CompanyDetail;
use App\Models\StockTransferShipment;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferShipment>
 */
class StockTransferShipmentFactory extends Factory
{
    /**
     * Default: Draft antara dua store baru di perusahaan baru (override company_id/store untuk skenario nyata).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = CompanyDetail::create(['name' => fake()->company()]);

        return [
            'company_id' => $company->id,
            'from_store_id' => fn () => $this->makeStore($company)->id,
            'to_store_id' => fn () => $this->makeStore($company)->id,
            'status' => StockTransferShipment::STATUS_DRAFT,
            'note' => null,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StockTransferShipment::STATUS_POSTED,
            'number' => 'STS-'.fake()->unique()->numerify('######'),
            'posted_at' => now(),
            'sap_post_status' => StockTransferShipment::SAP_PENDING,
        ]);
    }

    private function makeStore(CompanyDetail $company): Store
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);

        return Store::create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'code' => strtoupper(fake()->unique()->bothify('ST-###')),
            'name' => fake()->words(2, true),
            'pic_name' => fake()->name(),
            'currency' => 'IDR',
        ]);
    }
}
