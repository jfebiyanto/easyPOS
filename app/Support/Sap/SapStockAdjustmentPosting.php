<?php

namespace App\Support\Sap;

use App\Jobs\PostStockAdjustmentToSap;
use App\Models\CompanyDetail;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Posting penyesuaian stok easyPOS ke SAP B1: selisih positif menjadi Goods Receipt (OIGN, ObjType 59,
 * resource InventoryGenEntries) dan selisih negatif menjadi Goods Issue (OIGE, ObjType 60, resource
 * InventoryGenExits). Satu penyesuaian dapat menghasilkan keduanya.
 *
 * - Hanya gerai berwarehouse yang diposting; warehouse = warehouse gerai. Harga/cost tidak dikirim (cost hanya di SAP).
 * - Header dokumen: U_SOL_FRST = 'Y', U_SOL_TRKY = UUID penyesuaian, U_SOL_STID = UUID gerai;
 *   nomor penyesuaian di JournalMemo, alasan di Comments.
 * - Idempoten per dokumen: sebelum membuat, cari dokumen dengan U_SOL_TRKY yang sama di resource-nya.
 * - Kirim ulang melanjutkan dari dokumen yang belum ada.
 */
class SapStockAdjustmentPosting
{
    public const OBJ_GOODS_RECEIPT = 59;

    public const OBJ_GOODS_ISSUE = 60;

    /**
     * Antrekan posting bila integrasi aktif dan penyesuaian sudah di-Post tetapi belum terposting ke SAP.
     */
    public static function queue(StockAdjustment $adjustment): void
    {
        $company = CompanyDetail::find($adjustment->company_id);

        if (! $company?->sapIntegrationActive() || ! $adjustment->isPosted() || $adjustment->sap_post_status === StockAdjustment::SAP_POSTED) {
            return;
        }

        $adjustment->forceFill(['sap_post_status' => StockAdjustment::SAP_PENDING, 'sap_post_error' => null])->saveQuietly();

        PostStockAdjustmentToSap::dispatch($adjustment->id)->afterCommit();
    }

    public function __construct(private readonly CompanyDetail $company) {}

    public function post(StockAdjustment $adjustment): void
    {
        $adjustment->loadMissing(['lines.product', 'store.warehouse']);
        $client = new SapServiceLayer($this->company);

        try {
            $warehouse = $adjustment->store?->warehouse
                ?? throw new RuntimeException('Gerai belum dihubungkan ke warehouse.');

            $receipts = $adjustment->lines->filter(fn (StockAdjustmentLine $line) => (float) $line->delta > 0);
            $issues = $adjustment->lines->filter(fn (StockAdjustmentLine $line) => (float) $line->delta < 0);

            if ($receipts->isEmpty() && $issues->isEmpty()) {
                throw new RuntimeException("Adjustment {$adjustment->number} has no quantity difference to send to SAP B1.");
            }

            $client->login();

            if ($receipts->isNotEmpty() && $adjustment->sap_receipt_doc_entry === null) {
                $this->postDocument($client, $adjustment, 'InventoryGenEntries', $receipts, $warehouse->code, 'receipt', self::OBJ_GOODS_RECEIPT);
            }

            if ($issues->isNotEmpty() && $adjustment->sap_issue_doc_entry === null) {
                $this->postDocument($client, $adjustment, 'InventoryGenExits', $issues, $warehouse->code, 'issue', self::OBJ_GOODS_ISSUE);
            }

            $adjustment->forceFill([
                'sap_post_status' => StockAdjustment::SAP_POSTED,
                'sap_post_error' => null,
                'sap_posted_at' => now(),
            ])->saveQuietly();
        } catch (Throwable $e) {
            $adjustment->forceFill([
                'sap_post_status' => StockAdjustment::SAP_FAILED,
                'sap_post_error' => Str::limit($e->getMessage(), 500, ''),
            ])->saveQuietly();
        } finally {
            $client->logout();
        }
    }

    /**
     * @param  Collection<int, StockAdjustmentLine>  $lines
     */
    private function postDocument(SapServiceLayer $client, StockAdjustment $adjustment, string $resource, $lines, string $warehouseCode, string $column, int $objType): void
    {
        $document = $this->findByTrackingKey($client, $resource, $adjustment->id)
            ?? $client->create($resource, [
                'U_SOL_FRST' => 'Y',
                'U_SOL_TRKY' => $adjustment->id,
                'U_SOL_STID' => $adjustment->store_id,
                'DocDate' => $adjustment->date->toDateString(),
                'JournalMemo' => Str::limit((string) $adjustment->number, 50, ''),
                ...(filled(trim((string) $adjustment->reason)) ? ['Comments' => Str::limit(trim((string) $adjustment->reason), 254, '')] : []),
                'DocumentLines' => $lines->map(fn (StockAdjustmentLine $line) => [
                    'ItemCode' => $line->product?->sku ?? throw new RuntimeException("Adjustment {$adjustment->number} has a line without an item code."),
                    'Quantity' => abs((float) $line->delta),
                    'WarehouseCode' => $warehouseCode,
                ])->values()->all(),
            ]);

        $adjustment->forceFill([
            "sap_{$column}_obj_type" => $objType,
            "sap_{$column}_doc_num" => (string) $document['DocNum'],
            "sap_{$column}_doc_entry" => (int) $document['DocEntry'],
        ])->saveQuietly();
    }

    /**
     * @return ?array<string, mixed>
     */
    private function findByTrackingKey(SapServiceLayer $client, string $resource, string $trackingKey): ?array
    {
        $found = $client->find($resource, [
            '$select' => 'DocEntry,DocNum',
            '$filter' => "U_SOL_TRKY eq '{$trackingKey}'",
        ]);

        return $found['value'][0] ?? null;
    }
}
