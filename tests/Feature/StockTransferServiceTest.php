<?php

namespace Tests\Feature;

use App\Models\CompanyDetail;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockTransferReceipt;
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

class StockTransferServiceTest extends TestCase
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

        WarehouseStockService::record($this->source, $this->product->id, WarehouseStockLedger::TYPE_OPENING, 10, ['created_by' => null]);
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

    public function test_post_memindahkan_stok_ke_in_transit(): void
    {
        $shipment = $this->postedShipment([5]);

        $this->assertSame(5.0, $this->onHand($this->source));
        $this->assertSame(5.0, $this->onHand($this->transit));
        $this->assertSame(StockTransferShipment::STATUS_POSTED, $shipment->status);
        $this->assertNotNull($shipment->number);
        $this->assertNotNull($shipment->posted_at);
        $this->assertSame(StockTransferShipment::SAP_PENDING, $shipment->sap_post_status);
        $this->assertSame($this->source->id, $shipment->from_warehouse_id);
        $this->assertSame($this->transit->id, $shipment->transit_warehouse_id);
        $this->assertSame($this->destination->id, $shipment->to_warehouse_id);

        $ledger = WarehouseStockLedger::where('ref_type', 'stock_transfer_shipment')->where('ref_id', $shipment->id)->get();
        $this->assertEqualsCanonicalizing(
            [[$this->source->id, WarehouseStockLedger::TYPE_TRANSFER_OUT, -5.0], [$this->transit->id, WarehouseStockLedger::TYPE_TRANSFER_IN, 5.0]],
            $ledger->map(fn ($row) => [$row->warehouse_id, $row->type, (float) $row->qty])->all()
        );
        $this->assertSame([$this->owner->id], $ledger->pluck('created_by')->unique()->values()->all());
    }

    public function test_post_ditolak_tanpa_integrasi_sap(): void
    {
        $this->company->update(['sap_enabled' => false]);
        $shipment = $this->draftShipment();

        $this->assertRejected('SAP integration', fn () => (new StockTransferService)->post($shipment, $this->owner));
        $this->assertSame(StockTransferShipment::STATUS_DRAFT, $shipment->fresh()->status);
        $this->assertSame(10.0, $this->onHand($this->source));
    }

    public function test_post_ditolak_bila_in_transit_kosong_menyebut_kode(): void
    {
        $this->source->update(['transit_warehouse_code' => null]);
        $shipment = $this->draftShipment();

        $this->assertRejected(
            'Source warehouse G.001.MN has no in-transit warehouse (U_SOL_WHTR).',
            fn () => (new StockTransferService)->post($shipment, $this->owner)
        );
        $this->assertSame(10.0, $this->onHand($this->source));
    }

    public function test_post_ditolak_bila_store_tanpa_warehouse(): void
    {
        $this->toStore->update(['warehouse_id' => null]);
        $shipment = $this->draftShipment();

        $this->assertRejected('Store STR002 has no warehouse', fn () => (new StockTransferService)->post($shipment, $this->owner));
        $this->assertSame(StockTransferShipment::STATUS_DRAFT, $shipment->fresh()->status);
    }

    public function test_post_ditolak_stok_tidak_cukup(): void
    {
        $shipment = $this->draftShipment([11]);

        $this->assertRejected('Insufficient stock for INDF-0001 in warehouse G.001.MN', fn () => (new StockTransferService)->post($shipment, $this->owner));
        $this->assertSame(10.0, $this->onHand($this->source));
        $this->assertSame(0.0, $this->onHand($this->transit));
        $this->assertNull($shipment->fresh()->number);
    }

    public function test_post_ditolak_tanpa_baris_dan_bukan_draft(): void
    {
        $empty = $this->draftShipment([]);
        $this->assertRejected('no lines', fn () => (new StockTransferService)->post($empty, $this->owner));

        $posted = $this->postedShipment([2]);
        $this->assertRejected('Only draft', fn () => (new StockTransferService)->post($posted, $this->owner));
        $this->assertSame(8.0, $this->onHand($this->source));
    }

    public function test_receipt_penuh_menyelesaikan_shipment(): void
    {
        $shipment = $this->postedShipment([5]);
        $line = $shipment->lines()->first();

        $receipt = (new StockTransferService)->receive($shipment, [$line->id => 5], $this->owner, 'Arrived');

        $this->assertSame('Completed', $shipment->fresh()->displayStatus());
        $this->assertSame(0.0, $this->onHand($this->transit));
        $this->assertSame(5.0, $this->onHand($this->destination));
        $this->assertNotNull($receipt->number);
        $this->assertNotNull($receipt->received_at);
        $this->assertSame('Arrived', $receipt->note);
        $this->assertSame($this->toStore->id, $receipt->store_id);
        $this->assertSame(StockTransferReceipt::SAP_PENDING, $receipt->sap_post_status);
        $this->assertSame(5.0, (float) $receipt->lines()->sole()->qty);
        $this->assertSame(2, WarehouseStockLedger::where('ref_type', 'stock_transfer_receipt')->where('ref_id', $receipt->id)->count());
    }

    public function test_receipt_parsial_berulang_sampai_habis(): void
    {
        $shipment = $this->postedShipment([5]);
        $line = $shipment->lines()->first();
        $service = new StockTransferService;

        $service->receive($shipment, [$line->id => 3], $this->owner);
        $this->assertSame('Partially Received', $shipment->fresh()->displayStatus());

        $service->receive($shipment, [$line->id => 2], $this->owner);
        $this->assertSame('Completed', $shipment->fresh()->displayStatus());

        $this->assertRejected('exceeds remaining', fn () => $service->receive($shipment, [$line->id => 1], $this->owner));
        $this->assertSame(2, $shipment->receipts()->count());
        $this->assertSame(5.0, $this->onHand($this->destination));
    }

    public function test_receipt_melebihi_sisa_ditolak(): void
    {
        $shipment = $this->postedShipment([5]);
        $line = $shipment->lines()->first();

        $this->assertRejected('exceeds remaining', fn () => (new StockTransferService)->receive($shipment, [$line->id => 6], $this->owner));
        $this->assertRejected('greater than zero', fn () => (new StockTransferService)->receive($shipment, [$line->id => 0], $this->owner));
        $this->assertSame(0, $shipment->receipts()->count());
        $this->assertSame(5.0, $this->onHand($this->transit));
    }

    public function test_receipt_tanpa_baris_ditolak(): void
    {
        $shipment = $this->postedShipment([5]);
        $foreignLine = $this->postedShipment([1])->lines()->first();

        $this->assertRejected('at least one line', fn () => (new StockTransferService)->receive($shipment, [], $this->owner));
        $this->assertRejected('does not belong', fn () => (new StockTransferService)->receive($shipment, [$foreignLine->id => 1], $this->owner));
        $this->assertRejected('does not belong', fn () => (new StockTransferService)->receive($shipment, ['missing-line-id' => 1], $this->owner));
        $this->assertSame(0, $shipment->receipts()->count());
    }

    public function test_receipt_dari_shipment_draft_ditolak(): void
    {
        $draft = $this->draftShipment([5]);

        $this->assertRejected('not posted', fn () => (new StockTransferService)->receive($draft, [$draft->lines()->first()->id => 1], $this->owner));
    }

    public function test_baris_ganda_produk_sama_dihitung_per_baris(): void
    {
        $shipment = $this->postedShipment([3, 2]);
        [$first, $second] = $shipment->lines()->orderBy('qty', 'desc')->get()->all();
        $service = new StockTransferService;

        $this->assertRejected('exceeds remaining', fn () => $service->receive($shipment, [$second->id => 3], $this->owner));

        $service->receive($shipment, [$first->id => 3, $second->id => 2], $this->owner);
        $this->assertSame(3.0, (float) $first->fresh()->qty_received);
        $this->assertSame(2.0, (float) $second->fresh()->qty_received);
        $this->assertSame('Completed', $shipment->fresh()->displayStatus());

        $overStock = $this->draftShipment([3, 3]);
        $this->assertRejected('Insufficient stock', fn () => $service->post($overStock, $this->owner));
    }

    public function test_receipt_bersamaan_tidak_melewati_sisa(): void
    {
        $shipment = $this->postedShipment([5]);
        $staleShipment = StockTransferShipment::with('lines')->find($shipment->id);
        $line = $staleShipment->lines->first();
        $service = new StockTransferService;

        $service->receive($shipment, [$line->id => 5], $this->owner);
        $this->assertRejected('exceeds remaining', fn () => $service->receive($staleShipment, [$line->id => 5], $this->owner));

        $this->assertSame(0.0, $this->onHand($this->transit));
        $this->assertSame(5.0, $this->onHand($this->destination));
        $this->assertSame(5.0, (float) $line->fresh()->qty_received);
    }
}
