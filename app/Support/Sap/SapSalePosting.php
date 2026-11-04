<?php

namespace App\Support\Sap;

use App\Jobs\PostSaleToSap;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleLine;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Posting penjualan POS ke SAP B1: A/R Invoice lalu satu Incoming Payment tunai yang menunjuk
 * invoice tersebut (otomatis terekonsiliasi).
 *
 * - Header dokumen: U_SOL_FRST = 'Y', U_SOL_TRKY = UUID penjualan, U_SOL_STID = UUID gerai.
 * - Idempoten per langkah: sebelum membuat dokumen, cari dokumen dengan U_SOL_TRKY yang sama.
 * - Kirim ulang melanjutkan dari langkah terakhir (invoice yang sudah ada tidak dibuat lagi).
 * - CashSum = DocTotal SAP; bila berbeda dengan total easyPOS, pembayaran ditahan.
 */
class SapSalePosting
{
    public const OBJ_INVOICE = 13;

    public const OBJ_INCOMING_PAYMENT = 24;

    /** Toleransi selisih total (pembulatan mata uang). */
    private const TOTAL_TOLERANCE = 0.009;

    /**
     * Antrekan posting bila integrasi aktif dan penjualan belum terposting.
     */
    public static function queue(Sale $sale): void
    {
        $company = CompanyDetail::find($sale->company_id);

        if (! $company?->sapIntegrationActive() || $sale->status !== Sale::STATUS_COMPLETED || $sale->sap_post_status === Sale::SAP_POSTED) {
            return;
        }

        $sale->forceFill(['sap_post_status' => $sale->sap_invoice_doc_entry ? Sale::SAP_INVOICED : Sale::SAP_PENDING, 'sap_post_error' => null])->saveQuietly();

        PostSaleToSap::dispatch($sale->id)->afterCommit();
    }

    public function __construct(private readonly CompanyDetail $company) {}

    public function post(Sale $sale): void
    {
        $sale->loadMissing(['lines', 'customer', 'store.warehouse']);
        $client = new SapServiceLayer($this->company);

        try {
            $this->assertPostable($sale);
            $client->login();

            if ($sale->sap_invoice_doc_entry === null) {
                $this->postInvoice($client, $sale);
            }

            $docTotal = $this->invoiceTotal($client, $sale);
            if (abs($docTotal - (float) $sale->total) > self::TOTAL_TOLERANCE) {
                throw new RuntimeException(sprintf(
                    'Total A/R Invoice SAP (%s) berbeda dengan total easyPOS (%s); Incoming Payment ditahan untuk dicek.',
                    number_format($docTotal, 2, ',', '.'),
                    number_format((float) $sale->total, 2, ',', '.'),
                ));
            }

            if ($sale->sap_payment_doc_entry === null) {
                $this->postPayment($client, $sale, $docTotal);
            }

            $sale->forceFill([
                'sap_post_status' => Sale::SAP_POSTED,
                'sap_post_error' => null,
                'sap_posted_at' => now(),
            ])->saveQuietly();
        } catch (Throwable $e) {
            $sale->forceFill([
                'sap_post_status' => Sale::SAP_FAILED,
                'sap_post_error' => Str::limit($e->getMessage(), 500),
            ])->saveQuietly();
        } finally {
            $client->logout();
        }
    }

    private function assertPostable(Sale $sale): void
    {
        $customer = $sale->customer;
        if (! $customer || blank($customer->code) || (! $customer->isFromSap() && $customer->sap_sync_status !== Customer::SYNC_SYNCED)) {
            throw new RuntimeException('Customer penjualan belum memiliki CardCode SAP B1; posting menunggu customer tersinkron.');
        }

        if (! $sale->store?->warehouse) {
            throw new RuntimeException('Gerai belum dihubungkan ke warehouse.');
        }

        if (blank($sale->store->warehouse->cash_account)) {
            throw new RuntimeException("Akun kas warehouse {$sale->store->warehouse->code} belum diisi (UDF U_SOL_CASH_ACC di SAP B1).");
        }

        if ((float) $sale->shipping_cost > 0) {
            throw new RuntimeException('Ongkos kirim belum didukung pada posting SAP B1.');
        }
    }

    private function postInvoice(SapServiceLayer $client, Sale $sale): void
    {
        $document = $this->findByTrackingKey($client, 'Invoices', $sale->id)
            ?? $client->create('Invoices', $this->markers($sale) + [
                'CardCode' => $sale->customer->code,
                'DocDate' => $sale->business_date->toDateString(),
                'DocDueDate' => $sale->business_date->toDateString(),
                'TaxDate' => $sale->business_date->toDateString(),
                'NumAtCard' => Str::limit((string) $sale->number, 100, ''),
                'Comments' => Str::limit("easyPOS {$sale->number}", 254, ''),
                'DocumentLines' => $sale->lines->map(fn (SaleLine $line) => $this->invoiceLine($sale, $line))->values()->all(),
            ]);

        $sale->forceFill([
            'sap_post_status' => Sale::SAP_INVOICED,
            'sap_invoice_obj_type' => self::OBJ_INVOICE,
            'sap_invoice_doc_num' => (string) $document['DocNum'],
            'sap_invoice_doc_entry' => (int) $document['DocEntry'],
        ])->saveQuietly();
    }

    /**
     * Baris invoice memakai total baris setelah diskon, sebelum PPN (LineTotal) agar
     * pembulatan mengikuti easyPOS; SAP menghitung harga satuan dari LineTotal / Quantity.
     *
     * @return array<string, mixed>
     */
    private function invoiceLine(Sale $sale, SaleLine $line): array
    {
        // Inklusif maupun eksklusif: line_total - tax_amount = nilai sebelum PPN.
        $net = round((float) $line->line_total - (float) $line->tax_amount, 2);

        $payload = [
            'ItemCode' => $line->sku_snapshot,
            'Quantity' => (float) $line->qty,
            'LineTotal' => $net,
            'WarehouseCode' => $sale->store->warehouse->code,
        ];

        if (filled($line->tax_group_code)) {
            $payload['VatGroup'] = $line->tax_group_code;
        }

        return $payload;
    }

    private function invoiceTotal(SapServiceLayer $client, Sale $sale): float
    {
        $invoice = $client->find("Invoices({$sale->sap_invoice_doc_entry})", ['$select' => 'DocTotal']);

        return (float) ($invoice['DocTotal'] ?? throw new RuntimeException('A/R Invoice tidak ditemukan di SAP B1.'));
    }

    private function postPayment(SapServiceLayer $client, Sale $sale, float $docTotal): void
    {
        $document = $this->findByTrackingKey($client, 'IncomingPayments', $sale->id)
            ?? $client->create('IncomingPayments', $this->markers($sale) + [
                'CardCode' => $sale->customer->code,
                'DocDate' => $sale->business_date->toDateString(),
                'TaxDate' => $sale->business_date->toDateString(),
                'CashAccount' => $sale->store->warehouse->cash_account,
                'CashSum' => $docTotal,
                'Remarks' => Str::limit("easyPOS {$sale->number}", 254, ''),
                'PaymentInvoices' => [[
                    'DocEntry' => $sale->sap_invoice_doc_entry,
                    'InvoiceType' => 'it_Invoice',
                    'SumApplied' => $docTotal,
                ]],
            ]);

        $sale->forceFill([
            'sap_payment_obj_type' => self::OBJ_INCOMING_PAYMENT,
            'sap_payment_doc_num' => (string) $document['DocNum'],
            'sap_payment_doc_entry' => (int) $document['DocEntry'],
        ])->saveQuietly();
    }

    /**
     * @return ?array<string, mixed>
     */
    private function findByTrackingKey(SapServiceLayer $client, string $resource, string $trackingKey): ?array
    {
        $found = $client->find($resource, [
            '$select' => 'DocEntry,DocNum',
            '$filter' => "U_SOL_TRKY eq '{$trackingKey}' and Cancelled eq 'tNO'",
        ]);

        return $found['value'][0] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function markers(Sale $sale): array
    {
        return [
            'U_SOL_FRST' => 'Y',
            'U_SOL_TRKY' => $sale->id,
            'U_SOL_STID' => $sale->store_id,
        ];
    }
}
