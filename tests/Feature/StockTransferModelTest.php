<?php

namespace Tests\Feature;

use App\Models\StockTransferReceipt;
use App\Models\StockTransferShipment;
use App\Models\StockTransferShipmentLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_turunan_shipment(): void
    {
        $draft = StockTransferShipment::factory()->create();
        $this->assertSame('Draft', $draft->displayStatus());

        $shipment = StockTransferShipment::factory()->posted()->create();
        $first = StockTransferShipmentLine::factory()->create(['shipment_id' => $shipment->id, 'qty' => 10]);
        $second = StockTransferShipmentLine::factory()->create(['shipment_id' => $shipment->id, 'qty' => 5]);
        $this->assertSame('In Transit', $shipment->fresh()->displayStatus());

        $first->update(['qty_received' => 10]);
        $this->assertSame('Partially Received', $shipment->fresh()->displayStatus());

        $second->update(['qty_received' => 5]);
        $this->assertSame('Completed', $shipment->fresh()->displayStatus());
    }

    public function test_sisa_baris(): void
    {
        $line = StockTransferShipmentLine::factory()->create(['qty' => 10, 'qty_received' => 3.5]);

        $this->assertSame(6.5, $line->remaining());
    }

    public function test_doc_entry_sap_disembunyikan_dan_receipt_terhubung_ke_shipment(): void
    {
        $receipt = StockTransferReceipt::factory()->create(['sap_doc_entry' => 123, 'sap_doc_num' => '77']);

        $this->assertArrayNotHasKey('sap_doc_entry', $receipt->fresh()->toArray());
        $this->assertSame($receipt->shipment_id, $receipt->shipment->id);
        $this->assertCount(1, $receipt->shipment->receipts);
        $this->assertSame($receipt->shipment->to_store_id, $receipt->store_id);
    }
}
