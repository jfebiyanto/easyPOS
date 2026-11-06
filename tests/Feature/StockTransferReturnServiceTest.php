<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\NumberingConfig;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockTransferReturn;
use App\Models\StockTransferShipment;
use App\Models\StockTransferShipmentLine;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\StockTransferException;
use App\Support\StockTransferService;
use App\Support\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StockTransferReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDetail $company;

    private Warehouse $source;

    private Warehouse $transit;

    private Warehouse $destination;

    private Store $fromStore;

    private Store $toStore;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->company = CompanyDetail::create([
            'name' => 'Test Co',
            'allow_negative_stock' => false,
            'sap_enabled' => true,
            'sap_verify_ssl' => false,
            'sap_endpoint_url' => 'https://8.8.8.8:50000/b1s/v1',
            'sap_db_name' => 'SBO_TEST',
            'sap_username' => 'manager',
            'sap_password' => 'secret',
        ]);

        $this->transit = Warehouse::factory()->transit()->create(['company_id' => $this->company->id, 'code' => 'G.001.IT']);
        $this->source = Warehouse::factory()->create([
            'company_id' => $this->company->id, 'code' => 'G.001.MN', 'transit_warehouse_code' => 'G.001.IT',
        ]);
        $this->destination = Warehouse::factory()->create(['company_id' => $this->company->id, 'code' => 'G.002.MN']);

        $this->fromStore = Store::create([
            'company_id' => $this->company->id, 'code' => 'STR001', 'name' => 'Store 1', 'pic_name' => 'PIC',
            'currency' => 'IDR', 'warehouse_id' => $this->source->id,
        ]);
        $this->toStore = Store::create([
            'company_id' => $this->company->id, 'code' => 'STR002', 'name' => 'Store 2', 'pic_name' => 'PIC',
            'currency' => 'IDR', 'warehouse_id' => $this->destination->id,
        ]);

        $this->owner = User::create([
            'company_id' => $this->company->id, 'store_id' => $this->fromStore->id, 'name' => 'Owner',
            'username' => 'owner1', 'role' => User::ROLE_OWNER, 'is_active' => true, 'password' => 'password',
        ]);

        $group = ProductGroup::create(['company_id' => $this->company->id, 'code' => 'G1', 'name' => 'Umum']);
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->product = Product::create([
            'company_id' => $this->company->id, 'product_group_id' => $group->id, 'unit_id' => $unit->id,
            'sku' => 'INDF-0001', 'name' => 'Indomie', 'base_price' => 4000, 'track_stock' => true, 'is_active' => true,
        ]);

        WarehouseStockService::record($this->source, $this->product->id, WarehouseStockLedger::TYPE_OPENING, 20, ['created_by' => null]);
    }

    /**
     * @param  array<int, float>  $quantities  qty per baris
     */
    private function draftShipment(array $quantities = [5]): StockTransferShipment
    {
        $shipment = StockTransferShipment::create([
            'company_id' => $this->company->id,
            'from_store_id' => $this->fromStore->id,
            'to_store_id' => $this->toStore->id,
            'status' => StockTransferShipment::STATUS_DRAFT,
            'created_by' => $this->owner->id,
        ]);

        foreach ($quantities as $qty) {
            StockTransferShipmentLine::create(['shipment_id' => $shipment->id, 'product_id' => $this->product->id, 'qty' => $qty, 'qty_received' => 0]);
        }

        return $shipment;
    }

    private function postedShipment(array $quantities = [5]): StockTransferShipment
    {
        $shipment = $this->draftShipment($quantities);
        (new StockTransferService)->post($shipment, $this->owner);

        return $shipment->fresh();
    }

    private function onHand(Warehouse $warehouse): float
    {
        return (float) WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', $this->product->id)->value('on_hand');
    }

    private function assertRejected(string $expectedMessage, callable $action): void
    {
        try {
            $action();
            $this->fail('StockTransferException was not thrown.');
        } catch (StockTransferException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    private function ship(float $qty): StockTransferShipmentLine
    {
        return $this->postedShipment([$qty])->lines()->firstOrFail();
    }

    public function test_receive_10_return_2_of_12_completes_and_restores_sender(): void
    {
        $line = ($this->ship(12));
        $shipment = $line->shipment;
        $service = new StockTransferService;

        $service->receive($shipment, [$line->id => 10], $this->owner);
        $return = $service->returnToSender($shipment, [$line->id => 2], $this->owner, 'Damaged');

        $this->assertSame(0.0, $this->onHand($this->transit));
        $this->assertSame(10.0, $this->onHand($this->destination));
        $this->assertSame(10.0, $this->onHand($this->source));
        $this->assertSame('Completed', $shipment->fresh()->displayStatus());
        $this->assertSame(2.0, (float) $line->fresh()->qty_returned);
        $this->assertSame($this->fromStore->id, $return->store_id);
        $this->assertSame('Damaged', $return->note);
        $this->assertCount(1, $shipment->returns);
        $this->assertSame(StockTransferReturn::SAP_PENDING, $return->sap_post_status);
    }

    public function test_remaining_and_status_with_mixed_receipts_and_returns(): void
    {
        $line = $this->ship(12);
        $shipment = $line->shipment;
        $service = new StockTransferService;

        $this->assertSame('In Transit', $shipment->fresh()->displayStatus());

        $service->returnToSender($shipment, [$line->id => 3], $this->owner);
        $this->assertSame(9.0, $line->fresh()->remaining());
        $this->assertSame('Partially Received', $shipment->fresh()->displayStatus());

        $service->receive($shipment, [$line->id => 4], $this->owner);
        $this->assertSame(5.0, $line->fresh()->remaining());
        $this->assertSame('Partially Received', $shipment->fresh()->displayStatus());

        $service->returnToSender($shipment, [$line->id => 5], $this->owner);
        $this->assertSame(0.0, $line->fresh()->remaining());
        $this->assertSame('Completed', $shipment->fresh()->displayStatus());
    }

    public function test_return_over_remaining_is_rejected(): void
    {
        $line = $this->ship(12);
        (new StockTransferService)->receive($line->shipment, [$line->id => 10], $this->owner);

        $this->assertRejected('exceeds remaining 2', fn () => (new StockTransferService)->returnToSender($line->shipment, [$line->id => 3], $this->owner));
        $this->assertSame(0, StockTransferReturn::count());
        $this->assertSame(2.0, $this->onHand($this->transit));
    }

    public function test_receipt_after_return_limited_to_remainder(): void
    {
        $line = $this->ship(12);
        (new StockTransferService)->returnToSender($line->shipment, [$line->id => 7], $this->owner);

        $this->assertRejected('exceeds remaining 5', fn () => (new StockTransferService)->receive($line->shipment, [$line->id => 6], $this->owner));
        (new StockTransferService)->receive($line->shipment, [$line->id => 5], $this->owner);
        $this->assertSame(0.0, $line->fresh()->remaining());
    }

    public function test_empty_quantities_are_rejected(): void
    {
        $line = $this->ship(5);

        $this->assertRejected('at least one line', fn () => (new StockTransferService)->returnToSender($line->shipment, [], $this->owner));
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $line = $this->ship(5);

        $this->assertRejected('INDF-0001', fn () => (new StockTransferService)->returnToSender($line->shipment, [$line->id => 0], $this->owner));
    }

    public function test_draft_shipment_is_rejected(): void
    {
        $shipment = $this->draftShipment([5]);
        $line = $shipment->lines()->firstOrFail();

        $this->assertRejected('is not posted', fn () => (new StockTransferService)->returnToSender($shipment, [$line->id => 1], $this->owner));
    }

    public function test_foreign_line_is_rejected(): void
    {
        $line = $this->ship(5);
        $other = $this->ship(5);

        $this->assertRejected('does not belong', fn () => (new StockTransferService)->returnToSender($line->shipment, [$other->id => 1], $this->owner));
    }

    public function test_return_writes_two_ledger_rows_and_number(): void
    {
        $line = $this->ship(5);
        $return = (new StockTransferService)->returnToSender($line->shipment, [$line->id => 2], $this->owner);

        $ledger = WarehouseStockLedger::where('ref_type', 'stock_transfer_return')->where('ref_id', $return->id)->get();
        $this->assertEqualsCanonicalizing(
            [[$this->transit->id, WarehouseStockLedger::TYPE_TRANSFER_OUT, -2.0], [$this->source->id, WarehouseStockLedger::TYPE_TRANSFER_IN, 2.0]],
            $ledger->map(fn ($row) => [$row->warehouse_id, $row->type, (float) $row->qty])->all()
        );
        $this->assertSame([$this->owner->id], $ledger->pluck('created_by')->unique()->values()->all());
        $this->assertNotNull($return->number);
        $this->assertNotNull($return->returned_at);
        $this->assertTrue(NumberingConfig::where('transaction_type', 'stock_transfer_return')->exists());
    }
}
