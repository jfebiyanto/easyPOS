<?php

namespace App\Support\Sap;

use App\Jobs\PostStockTransferToSap;
use App\Models\CompanyDetail;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferReceiptLine;
use App\Models\StockTransferShipment;
use App\Models\StockTransferShipmentLine;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Posting Stock Transfer Shipment dan Receipt easyPOS ke SAP B1 sebagai Stock Transfer (OWTR).
 *
 * - Shipment: gudang pengirim → gudang in-transit; Receipt: gudang in-transit → gudang penerima.
 * - Header dokumen: U_SOL_FRST = 'Y', U_SOL_TRKY = UUID dokumen, U_SOL_STID = UUID store pembuat
 *   (store pengirim untuk Shipment, store penerima untuk Receipt).
 * - Idempoten: sebelum membuat, cari Stock Transfer dengan U_SOL_TRKY yang sama.
 * - Receipt menunggu (tetap pending) sampai Shipment-nya terposting; setelah Shipment terposting,
 *   Receipt yang pending atau belum pernah diantrekan diantrekan ulang.
 */
class SapStockTransferPosting
{
    public const OBJ_STOCK_TRANSFER = 67;

    /**
     * Antrekan posting bila integrasi aktif dan dokumen belum terposting (juga untuk kirim ulang dokumen failed).
     */
    public static function queue(StockTransferShipment|StockTransferReceipt $doc): void
    {
        $company = CompanyDetail::find($doc->company_id);

        if (! $company?->sapIntegrationActive() || $doc->sap_post_status === $doc::SAP_POSTED) {
            return;
        }

        $doc->forceFill(['sap_post_status' => $doc::SAP_PENDING, 'sap_post_error' => null])->saveQuietly();

        PostStockTransferToSap::dispatch($doc::class, $doc->id)->afterCommit();
    }

    public function __construct(private readonly CompanyDetail $company) {}

    public function post(StockTransferShipment|StockTransferReceipt $doc): void
    {
        if ($doc instanceof StockTransferReceipt && $doc->shipment?->sap_post_status !== StockTransferShipment::SAP_POSTED) {
            // Menunggu Shipment terposting; job Shipment mengantrekan ulang Receipt ini.
            $doc->forceFill(['sap_post_status' => $doc::SAP_PENDING])->saveQuietly();

            return;
        }

        $client = new SapServiceLayer($this->company);

        try {
            $payload = $this->payload($doc);
            $client->login();

            $document = $this->findByTrackingKey($client, $doc->id) ?? $client->create('StockTransfers', $payload);

            $doc->forceFill([
                'sap_post_status' => $doc::SAP_POSTED,
                'sap_post_error' => null,
                'sap_doc_entry' => (int) $document['DocEntry'],
                'sap_doc_num' => (string) $document['DocNum'],
                'sap_posted_at' => now(),
            ])->saveQuietly();
        } catch (Throwable $e) {
            $doc->forceFill([
                'sap_post_status' => $doc::SAP_FAILED,
                'sap_post_error' => Str::limit($e->getMessage(), 500, ''),
            ])->saveQuietly();

            return;
        } finally {
            $client->logout();
        }

        if ($doc instanceof StockTransferShipment) {
            $this->queueWaitingReceipts($doc);
        }
    }

    /**
     * Receipt yang menunggu (pending) atau belum pernah diantrekan (integrasi sempat mati) dikirim setelah Shipment terposting.
     */
    private function queueWaitingReceipts(StockTransferShipment $shipment): void
    {
        $shipment->receipts()
            ->where(fn ($query) => $query->whereNull('sap_post_status')->orWhere('sap_post_status', StockTransferReceipt::SAP_PENDING))
            ->orderBy('received_at')
            ->get()
            ->each(fn (StockTransferReceipt $receipt) => self::queue($receipt));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StockTransferShipment|StockTransferReceipt $doc): array
    {
        if ($doc instanceof StockTransferShipment) {
            $doc->loadMissing(['fromWarehouse', 'transitWarehouse', 'lines.product']);
            $from = $this->requireWarehouse($doc->fromWarehouse, 'source');
            $to = $this->requireWarehouse($doc->transitWarehouse, 'in-transit');
            $date = $doc->posted_at;
            $storeId = $doc->from_store_id;
            $lines = $doc->lines
                ->filter(fn (StockTransferShipmentLine $line) => (float) $line->qty > 0)
                ->map(fn (StockTransferShipmentLine $line) => [$line->product?->sku, (float) $line->qty]);
        } else {
            $doc->loadMissing(['shipment.transitWarehouse', 'shipment.toWarehouse', 'lines.shipmentLine.product']);
            $from = $this->requireWarehouse($doc->shipment->transitWarehouse, 'in-transit');
            $to = $this->requireWarehouse($doc->shipment->toWarehouse, 'destination');
            $date = $doc->received_at;
            $storeId = $doc->store_id;
            $lines = $doc->lines
                ->filter(fn (StockTransferReceiptLine $line) => (float) $line->qty > 0)
                ->map(fn (StockTransferReceiptLine $line) => [$line->shipmentLine?->product?->sku, (float) $line->qty]);
        }

        if ($lines->isEmpty()) {
            throw new RuntimeException("Document {$doc->number} has no lines to post.");
        }

        return [
            'U_SOL_FRST' => 'Y',
            'U_SOL_TRKY' => $doc->id,
            'U_SOL_STID' => $storeId,
            'FromWarehouse' => $from->code,
            'ToWarehouse' => $to->code,
            'DocDate' => $this->documentDate($date),
            'Comments' => $this->comments($doc),
            'StockTransferLines' => $lines->map(function (array $line) use ($from, $to, $doc) {
                [$sku, $qty] = $line;

                if (blank($sku)) {
                    throw new RuntimeException("Document {$doc->number} has a line without an item code.");
                }

                return [
                    'ItemCode' => $sku,
                    'Quantity' => $qty,
                    'FromWarehouseCode' => $from->code,
                    'WarehouseCode' => $to->code,
                ];
            })->values()->all(),
        ];
    }

    private function requireWarehouse(?Warehouse $warehouse, string $role): Warehouse
    {
        return $warehouse ?? throw new RuntimeException("The {$role} warehouse of this stock transfer is not set.");
    }

    /**
     * Tanggal dokumen SAP = tanggal kejadian di zona waktu perusahaan.
     */
    private function documentDate(?Carbon $at): string
    {
        return ($at ?? now())->copy()->setTimezone($this->company->timezone ?: 'Asia/Jakarta')->toDateString();
    }

    /**
     * @return ?array<string, mixed>
     */
    private function findByTrackingKey(SapServiceLayer $client, string $trackingKey): ?array
    {
        $found = $client->find('StockTransfers', [
            '$select' => 'DocEntry,DocNum',
            '$filter' => "U_SOL_TRKY eq '{$trackingKey}'",
        ]);

        return $found['value'][0] ?? null;
    }

    /**
     * Comments dokumen SAP (maks. 254 karakter): isi Notes dokumen easyPOS bila ada (alasan transfer),
     * selain itu penanda "easyPOS {nomor}".
     */
    private function comments(StockTransferShipment|StockTransferReceipt $doc): string
    {
        $note = trim((string) $doc->note);

        return Str::limit($note !== '' ? $note : "easyPOS {$doc->number}", 254, '');
    }
}
