<?php

namespace App\Support\Sap;

use App\Jobs\PostReturnToSap;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StoreClosure;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Posting retur penjualan POS ke SAP B1: A/R Credit Memo yang mengikuti baris A/R Invoice asal,
 * lalu (hanya untuk pengembalian dana tunai) satu Outgoing Payment ke pelanggan yang menunjuk
 * Credit Memo tersebut (otomatis terekonsiliasi).
 *
 * - Credit Memo dibuat tanpa base document (invoice yang sudah dibayar berstatus closed dan ditolak SAP); harga, PPN,
 *   dan akun disalin dari DocumentLines invoice asal, dicocokkan lewat ItemCode.
 * - Header dokumen: U_SOL_FRST = 'Y', U_SOL_TRKY = UUID retur, U_SOL_STID = UUID gerai; nomor retur di JournalMemo.
 * - Idempoten per langkah: sebelum membuat dokumen, cari dokumen dengan U_SOL_TRKY yang sama.
 * - Retur menunggu sampai penjualan asalnya punya A/R Invoice di SAP.
 */
class SapReturnPosting
{
    public const OBJ_CREDIT_MEMO = 14;

    public const OBJ_OUTGOING_PAYMENT = 46;

    /** Toleransi selisih total (pembulatan mata uang). */
    private const TOTAL_TOLERANCE = 0.009;

    /**
     * Antrekan posting bila integrasi aktif dan retur belum terposting.
     */
    public static function queue(SalesReturn $return): void
    {
        $company = CompanyDetail::find($return->company_id);

        if (! $company?->sapIntegrationActive() || $return->sap_post_status === SalesReturn::SAP_POSTED) {
            return;
        }

        $return->forceFill([
            'sap_post_status' => $return->sap_credit_memo_doc_entry ? SalesReturn::SAP_CREDITED : SalesReturn::SAP_PENDING,
            'sap_post_error' => null,
        ])->saveQuietly();

        PostReturnToSap::dispatch($return->id)->afterCommit();
    }

    public function __construct(private readonly CompanyDetail $company) {}

    public function post(SalesReturn $return): void
    {
        $return->loadMissing(['lines.product', 'originalSale.customer', 'store.warehouse']);
        $client = new SapServiceLayer($this->company);

        try {
            $this->assertPostable($return);
            $client->login();

            if ($return->sap_credit_memo_doc_entry === null) {
                $this->postCreditMemo($client, $return);
            }

            $docTotal = $this->creditMemoTotal($client, $return);
            if (abs($docTotal - (float) $return->total) > self::TOTAL_TOLERANCE) {
                throw new RuntimeException(sprintf(
                    'Total A/R Credit Memo SAP (%s) berbeda dengan total retur easyPOS (%s); pengembalian dana ditahan untuk dicek.',
                    number_format($docTotal, 2, ',', '.'),
                    number_format((float) $return->total, 2, ',', '.'),
                ));
            }

            if ($return->isCashRefund() && $return->sap_refund_doc_entry === null) {
                $this->postRefund($client, $return, $docTotal);
            }

            $return->forceFill([
                'sap_post_status' => SalesReturn::SAP_POSTED,
                'sap_post_error' => null,
                'sap_posted_at' => now(),
            ])->saveQuietly();
        } catch (Throwable $e) {
            $return->forceFill([
                'sap_post_status' => SalesReturn::SAP_FAILED,
                'sap_post_error' => Str::limit($e->getMessage(), 500),
            ])->saveQuietly();
        } finally {
            $client->logout();
        }
    }

    private function assertPostable(SalesReturn $return): void
    {
        $sale = $return->originalSale;

        if (! $sale || $sale->sap_invoice_doc_entry === null) {
            throw new RuntimeException('Penjualan asal belum memiliki A/R Invoice di SAP B1; posting retur menunggu penjualan terposting.');
        }

        $customer = $sale->customer;
        if (! $customer || blank($customer->code) || (! $customer->isFromSap() && $customer->sap_sync_status !== Customer::SYNC_SYNCED)) {
            throw new RuntimeException('Customer penjualan belum memiliki CardCode SAP B1; posting retur menunggu customer tersinkron.');
        }

        $warehouse = $return->store?->warehouse;
        if (! $warehouse) {
            throw new RuntimeException('Gerai belum dihubungkan ke warehouse.');
        }

        if ($return->isCashRefund() && blank($warehouse->cash_account)) {
            throw new RuntimeException("Akun kas warehouse {$warehouse->code} belum diisi (UDF U_SOL_CASH_ACC di SAP B1).");
        }
    }

    private function postCreditMemo(SapServiceLayer $client, SalesReturn $return): void
    {
        $document = $this->findByTrackingKey($client, 'CreditNotes', $return->id);

        if ($document === null) {
            $date = $this->documentDate($return);

            $document = $client->create('CreditNotes', $this->markers($return) + [
                'CardCode' => $return->originalSale->customer->code,
                'DocDate' => $date,
                'DocDueDate' => $date,
                'TaxDate' => $date,
                'NumAtCard' => Str::limit((string) $return->number, 100, ''),
                'JournalMemo' => Str::limit((string) $return->number, 50, ''),
                // Credit Memo tanpa base document: nomor struk penjualan asal dicatat di Comments sebagai referensi.
                'Comments' => Str::limit('Return from Invoice No. '.$return->originalSale->number, 254, ''),
                'DocumentLines' => $this->creditMemoLines($client, $return),
            ]);
        }

        $return->forceFill([
            'sap_post_status' => SalesReturn::SAP_CREDITED,
            'sap_credit_memo_obj_type' => self::OBJ_CREDIT_MEMO,
            'sap_credit_memo_doc_num' => (string) $document['DocNum'],
            'sap_credit_memo_doc_entry' => (int) $document['DocEntry'],
        ])->saveQuietly();
    }

    /**
     * Baris Credit Memo (tanpa base document) memakai harga, PPN, dan akun dari baris A/R Invoice asal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function creditMemoLines(SapServiceLayer $client, SalesReturn $return): array
    {
        $invoiceEntry = (int) $return->originalSale->sap_invoice_doc_entry;
        $invoice = $client->find("Invoices({$invoiceEntry})", ['$select' => 'DocumentLines'])
            ?? throw new RuntimeException('A/R Invoice asal tidak ditemukan di SAP B1.');

        $invoiceLines = collect($invoice['DocumentLines'] ?? []);

        return $return->lines->map(function (SalesReturnLine $line) use ($invoiceLines, $return) {
            $sku = (string) ($line->product?->sku ?? $line->saleLine?->sku_snapshot);
            $base = $invoiceLines->firstWhere('ItemCode', $sku)
                ?? throw new RuntimeException("Baris {$sku} tidak ditemukan pada A/R Invoice asal di SAP B1.");

            $warehouse = $return->store->warehouse;

            $inclusive = (bool) $this->company->ppn_inclusive;
            $price = $inclusive ? ($base['PriceAfterVAT'] ?? null) : ($base['Price'] ?? $base['UnitPrice'] ?? null);

            return [
                'ItemCode' => $sku,
                'Quantity' => (float) $line->qty,
                'WarehouseCode' => $warehouse->code,
                // Invoice yang sudah dibayar berstatus closed dan ditolak sebagai base document oleh Service Layer;
                // harga, PPN, dan akun pendapatan disalin dari baris invoice asal.
                ...(filled($price) ? [$inclusive ? 'PriceAfterVAT' : 'Price' => (float) $price] : []),
                ...(filled($base['VatGroup'] ?? null) ? ['VatGroup' => $base['VatGroup']] : []),
                ...(filled($base['AccountCode'] ?? null) ? ['AccountCode' => $base['AccountCode']] : []),
                // Profit center warehouse = dimensi 1 pendapatan dan HPP, sama seperti A/R Invoice (CostingCode/COGSCostingCode).
                ...(filled($warehouse->profit_center) ? ['CostingCode' => $warehouse->profit_center, 'COGSCostingCode' => $warehouse->profit_center] : []),
            ];
        })->values()->all();
    }

    private function creditMemoTotal(SapServiceLayer $client, SalesReturn $return): float
    {
        $memo = $client->find("CreditNotes({$return->sap_credit_memo_doc_entry})", ['$select' => 'DocTotal']);

        return (float) ($memo['DocTotal'] ?? throw new RuntimeException('A/R Credit Memo tidak ditemukan di SAP B1.'));
    }

    private function postRefund(SapServiceLayer $client, SalesReturn $return, float $docTotal): void
    {
        $document = $this->findByTrackingKey($client, 'VendorPayments', $return->id)
            ?? $client->create('VendorPayments', $this->markers($return) + [
                'DocType' => 'rCustomer',
                'CardCode' => $return->originalSale->customer->code,
                'DocDate' => $this->documentDate($return),
                'TaxDate' => $this->documentDate($return),
                'JournalRemarks' => Str::limit((string) $return->number, 50, ''),
                'CashAccount' => $return->store->warehouse->cash_account,
                'CashSum' => $docTotal,
                'PaymentInvoices' => [[
                    'DocEntry' => $return->sap_credit_memo_doc_entry,
                    'InvoiceType' => 'it_CredItnote',
                    'SumApplied' => $docTotal,
                ]],
            ]);

        $return->forceFill([
            'sap_refund_obj_type' => self::OBJ_OUTGOING_PAYMENT,
            'sap_refund_doc_num' => (string) $document['DocNum'],
            'sap_refund_doc_entry' => (int) $document['DocEntry'],
        ])->saveQuietly();
    }

    /**
     * Tanggal dokumen = tanggal bisnis gerai saat retur dibuat (mengikuti zona waktu perusahaan).
     */
    private function documentDate(SalesReturn $return): string
    {
        $date = $return->created_at->copy()->setTimezone($this->company->timezone ?: 'Asia/Jakarta')->toDateString();

        return StoreClosure::postingDate($return->store_id, $date);
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
    private function markers(SalesReturn $return): array
    {
        return [
            'U_SOL_FRST' => 'Y',
            'U_SOL_TRKY' => $return->id,
            'U_SOL_STID' => $return->store_id,
        ];
    }
}
