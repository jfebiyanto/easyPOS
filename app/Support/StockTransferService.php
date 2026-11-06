<?php

namespace App\Support;

use App\Models\CompanyDetail;
use App\Models\NumberingConfig;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferReturn;
use App\Models\StockTransferShipment;
use App\Models\StockTransferShipmentLine;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockLedger;
use App\Support\Sap\SapStockTransferPosting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Transfer stok dua langkah lewat buku besar warehouse:
 * - Post Shipment: gudang pengirim → gudang in-transit pengirim (U_SOL_WHTR).
 * - Receipt: gudang in-transit → gudang store penerima, boleh parsial berulang sampai sisa habis.
 *
 * Keduanya diantrekan ke SAP B1 sebagai Stock Transfer. Pelanggaran syarat melempar StockTransferException.
 */
class StockTransferService
{
    public const REF_SHIPMENT = 'stock_transfer_shipment';

    public const REF_RECEIPT = 'stock_transfer_receipt';

    public const REF_RETURN = 'stock_transfer_return';

    /**
     * Post Shipment Draft: validasi syarat, pindahkan stok ke in-transit, beri nomor, antrekan SAP.
     */
    public function post(StockTransferShipment $shipment, User $by): void
    {
        DB::transaction(function () use ($shipment, $by) {
            $locked = StockTransferShipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockTransferShipment::STATUS_DRAFT) {
                throw new StockTransferException('Only draft shipments can be posted.');
            }

            $company = CompanyDetail::find($locked->company_id);

            if (! $company?->sapIntegrationActive()) {
                throw new StockTransferException('SAP integration is not active; stock transfers cannot be posted.');
            }

            $source = $this->storeWarehouse($locked->from_store_id);
            $destination = $this->storeWarehouse($locked->to_store_id);
            $transit = $source->transitWarehouse();

            if (! $transit) {
                throw new StockTransferException(blank($source->transit_warehouse_code)
                    ? "Source warehouse {$source->code} has no in-transit warehouse (U_SOL_WHTR)."
                    : "In-transit warehouse {$source->transit_warehouse_code} of {$source->code} was not found or is not flagged U_SOL_ITWH.");
            }

            // Urut product_id agar kunci baris warehouse_stocks selalu diambil dalam urutan yang sama (hindari deadlock).
            $lines = $locked->lines()->with('product')->get()
                ->filter(fn (StockTransferShipmentLine $line) => (float) $line->qty > 0)
                ->sortBy('product_id')
                ->values();

            if ($lines->isEmpty()) {
                throw new StockTransferException('Shipment has no lines with quantity greater than zero.');
            }

            if (! $company->allow_negative_stock) {
                $this->assertStockAvailable($source, $lines);
            }

            $options = ['ref_type' => self::REF_SHIPMENT, 'ref_id' => $locked->id, 'created_by' => $by->id];

            foreach ($lines as $line) {
                WarehouseStockService::record($source, $line->product_id, WarehouseStockLedger::TYPE_TRANSFER_OUT, -(float) $line->qty, $options);
                WarehouseStockService::record($transit, $line->product_id, WarehouseStockLedger::TYPE_TRANSFER_IN, (float) $line->qty, $options);
            }

            $locked->forceFill([
                'from_warehouse_id' => $source->id,
                'transit_warehouse_id' => $transit->id,
                'to_warehouse_id' => $destination->id,
                'status' => StockTransferShipment::STATUS_POSTED,
                'posted_at' => now(),
                'number' => NumberingConfig::nextNumber(self::REF_SHIPMENT, null, $locked->company_id),
            ])->save();

            SapStockTransferPosting::queue($locked);
        });

        $shipment->refresh();
    }

    /**
     * Terima barang dari Shipment yang posted: in-transit → gudang penerima.
     *
     * @param  array<string, float|int|string>  $quantities  shipment_line_id => qty diterima; baris yang tidak ada = tidak diterima
     */
    public function receive(StockTransferShipment $shipment, array $quantities, User $by, ?string $note = null): StockTransferReceipt
    {
        return DB::transaction(function () use ($shipment, $quantities, $by, $note) {
            $locked = StockTransferShipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockTransferShipment::STATUS_POSTED) {
                throw new StockTransferException("Shipment {$this->label($locked)} is not posted.");
            }

            if ($quantities === []) {
                throw new StockTransferException('A receipt must have at least one line.');
            }

            $lines = StockTransferShipmentLine::query()
                ->where('shipment_id', $locked->id)
                ->whereIn('id', array_map('strval', array_keys($quantities)))
                ->with('product')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $received = [];

            foreach ($quantities as $lineId => $rawQty) {
                $line = $lines->get((string) $lineId);

                if (! $line) {
                    throw new StockTransferException("Line {$lineId} does not belong to shipment {$this->label($locked)}.");
                }

                $qty = round((float) $rawQty, 3);
                $sku = $line->product?->sku ?? $line->product_id;

                if ($qty <= 0) {
                    throw new StockTransferException("Received quantity for {$sku} must be greater than zero.");
                }

                if ($qty > $line->remaining()) {
                    throw new StockTransferException("Received quantity for {$sku} ({$qty}) exceeds remaining {$line->remaining()} on shipment {$this->label($locked)}.");
                }

                $received[] = [$line, $qty];
            }

            $transit = $locked->transit_warehouse_id ? Warehouse::find($locked->transit_warehouse_id) : null;
            $destination = $locked->to_warehouse_id ? Warehouse::find($locked->to_warehouse_id) : null;

            if (! $transit || ! $destination) {
                throw new StockTransferException("Shipment {$this->label($locked)} has no in-transit or destination warehouse.");
            }

            $receipt = StockTransferReceipt::create([
                'company_id' => $locked->company_id,
                'shipment_id' => $locked->id,
                'number' => NumberingConfig::nextNumber(self::REF_RECEIPT, null, $locked->company_id),
                'store_id' => $locked->to_store_id,
                'note' => $note,
                'received_at' => now(),
                'created_by' => $by->id,
            ]);

            $options = ['ref_type' => self::REF_RECEIPT, 'ref_id' => $receipt->id, 'created_by' => $by->id];

            usort($received, fn (array $a, array $b) => strcmp($a[0]->product_id, $b[0]->product_id));

            foreach ($received as [$line, $qty]) {
                $receipt->lines()->create(['shipment_line_id' => $line->id, 'qty' => $qty]);

                WarehouseStockService::record($transit, $line->product_id, WarehouseStockLedger::TYPE_TRANSFER_OUT, -$qty, $options);
                WarehouseStockService::record($destination, $line->product_id, WarehouseStockLedger::TYPE_TRANSFER_IN, $qty, $options);

                $line->forceFill(['qty_received' => round((float) $line->qty_received + $qty, 3)])->save();
            }

            SapStockTransferPosting::queue($receipt);

            return $receipt->refresh();
        });
    }

    /**
     * Kembalikan barang yang masih in-transit ke gudang pengirim: in-transit → gudang pengirim.
     *
     * @param  array<string, float|int|string>  $quantities  shipment_line_id => qty dikembalikan
     */
    public function returnToSender(StockTransferShipment $shipment, array $quantities, User $by, ?string $note = null): StockTransferReturn
    {
        return DB::transaction(function () use ($shipment, $quantities, $by, $note) {
            $locked = StockTransferShipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockTransferShipment::STATUS_POSTED) {
                throw new StockTransferException("Shipment {$this->label($locked)} is not posted.");
            }

            if ($quantities === []) {
                throw new StockTransferException('A return must have at least one line.');
            }

            $lines = StockTransferShipmentLine::query()
                ->where('shipment_id', $locked->id)
                ->whereIn('id', array_map('strval', array_keys($quantities)))
                ->with('product')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $returned = [];

            foreach ($quantities as $lineId => $rawQty) {
                $line = $lines->get((string) $lineId);

                if (! $line) {
                    throw new StockTransferException("Line {$lineId} does not belong to shipment {$this->label($locked)}.");
                }

                $qty = round((float) $rawQty, 3);
                $sku = $line->product?->sku ?? $line->product_id;

                if ($qty <= 0) {
                    throw new StockTransferException("Returned quantity for {$sku} must be greater than zero.");
                }

                if ($qty > $line->remaining()) {
                    throw new StockTransferException("Returned quantity for {$sku} ({$qty}) exceeds remaining {$line->remaining()} on shipment {$this->label($locked)}.");
                }

                $returned[] = [$line, $qty];
            }

            $transit = $locked->transit_warehouse_id ? Warehouse::find($locked->transit_warehouse_id) : null;
            $source = $locked->from_warehouse_id ? Warehouse::find($locked->from_warehouse_id) : null;

            if (! $transit || ! $source) {
                throw new StockTransferException("Shipment {$this->label($locked)} has no in-transit or source warehouse.");
            }

            $return = StockTransferReturn::create([
                'company_id' => $locked->company_id,
                'shipment_id' => $locked->id,
                'number' => NumberingConfig::nextNumber(self::REF_RETURN, null, $locked->company_id),
                'store_id' => $locked->from_store_id,
                'note' => $note,
                'returned_at' => now(),
                'created_by' => $by->id,
            ]);

            $options = ['ref_type' => self::REF_RETURN, 'ref_id' => $return->id, 'created_by' => $by->id];

            usort($returned, fn (array $a, array $b) => strcmp($a[0]->product_id, $b[0]->product_id));

            foreach ($returned as [$line, $qty]) {
                $return->lines()->create(['shipment_line_id' => $line->id, 'qty' => $qty]);

                WarehouseStockService::record($transit, $line->product_id, WarehouseStockLedger::TYPE_TRANSFER_OUT, -$qty, $options);
                WarehouseStockService::record($source, $line->product_id, WarehouseStockLedger::TYPE_TRANSFER_IN, $qty, $options);

                $line->forceFill(['qty_returned' => round((float) $line->qty_returned + $qty, 3)])->save();
            }

            SapStockTransferPosting::queue($return);

            return $return->refresh();
        });
    }

    private function storeWarehouse(string $storeId): Warehouse
    {
        $store = Store::find($storeId);
        $warehouse = $store?->warehouse_id ? Warehouse::find($store->warehouse_id) : null;

        if (! $warehouse) {
            throw new StockTransferException('Store '.($store?->code ?? $storeId).' has no warehouse.');
        }

        return $warehouse;
    }

    /**
     * Tolak bila saldo gudang pengirim menjadi negatif; baris ganda produk yang sama dijumlahkan.
     *
     * @param  Collection<int, StockTransferShipmentLine>  $lines
     */
    private function assertStockAvailable(Warehouse $source, Collection $lines): void
    {
        foreach ($lines->groupBy('product_id')->sortKeys() as $productId => $productLines) {
            $onHand = (float) WarehouseStock::query()
                ->where('warehouse_id', $source->id)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->value('on_hand');
            $after = round($onHand - (float) $productLines->sum('qty'), 3);

            if ($after < 0) {
                $sku = $productLines->first()->product?->sku ?? $productId;

                throw new StockTransferException("Insufficient stock for {$sku} in warehouse {$source->code} (balance would be {$after}).");
            }
        }
    }

    private function label(StockTransferShipment $shipment): string
    {
        return $shipment->number ?? $shipment->id;
    }
}
