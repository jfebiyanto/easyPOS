<?php

namespace App\Support\Sap;

use App\Jobs\PostSaleToSap;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\PaymentType;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Posting penjualan POS ke SAP B1: A/R Invoice lalu satu Incoming Payment yang menunjuk
 * invoice tersebut (otomatis terekonsiliasi).
 *
 * - Pembayaran tunai (tipe berkode CASH) = CashAccount/CashSum (akun kas warehouse). Tipe lain wajib
 *   dipetakan ke kartu kredit SAP (menu Tipe Pembayaran) dan dicatat sebagai Credit Card
 *   (PaymentCreditCards) dengan nomor kartu default; akun: UDF QRIS/EDC warehouse untuk tipe QRIS/EDC,
 *   selain itu akun GL kartu kredit SAP.
 * - Baris invoice memakai profit center warehouse (CostingCode/COGSCostingCode di Service Layer) bila terisi, dan harga satuan di PriceAfterVAT (PPN inklusif) atau Price (eksklusif).
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

    /** Nomor kartu bawaan untuk pembayaran QRIS/EDC (kartu sebenarnya tidak diketahui easyPOS). */
    private const DEFAULT_CARD_NUMBER = '1234';

    private const METHOD_CASH = 'CASH';

    private const METHOD_CARD = 'CARD';

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
        $sale->loadMissing(['lines', 'customer', 'store.warehouse', 'payments.paymentType.creditCard']);
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

        $this->assertPaymentsPostable($sale);

        if ((float) $sale->shipping_cost > 0) {
            throw new RuntimeException('Ongkos kirim belum didukung pada posting SAP B1.');
        }
    }

    /**
     * Setiap pembayaran harus bertipe CASH atau dipetakan ke kartu kredit SAP, dengan akun yang terisi;
     * pembayaran non-tunai harus sudah terkonfirmasi (pembayaran pending menahan posting).
     */
    private function assertPaymentsPostable(Sale $sale): void
    {
        $warehouse = $sale->store->warehouse;

        foreach ($sale->payments as $payment) {
            $method = $this->method($payment);
            $code = $payment->paymentType?->code ?? '-';

            if ($method === null) {
                throw new RuntimeException("Tipe pembayaran {$code} belum dipetakan ke Credit Card SAP B1 (menu Tipe Pembayaran); posting menunggu pemetaan.");
            }

            if ($method === self::METHOD_CASH) {
                continue;
            }

            if ($payment->status !== 'confirmed') {
                throw new RuntimeException("Pembayaran {$code} belum terkonfirmasi; posting menunggu konfirmasi.");
            }

            if (blank($this->account($warehouse, $payment))) {
                throw new RuntimeException($this->missingAccountMessage($warehouse, $payment));
            }
        }

        if ($sale->payments->contains(fn (SalePayment $payment) => $this->method($payment) === self::METHOD_CASH) && blank($warehouse->cash_account)) {
            throw new RuntimeException("Akun kas warehouse {$warehouse->code} belum diisi (UDF U_SOL_CASH_ACC di SAP B1).");
        }
    }

    /**
     * @return ?string METHOD_CASH (tipe berkode CASH), METHOD_CARD (dipetakan ke kartu kredit SAP), atau null
     */
    private function method(SalePayment $payment): ?string
    {
        $type = $payment->paymentType;

        if (strtoupper((string) $type?->code) === PaymentType::CODE_CASH) {
            return self::METHOD_CASH;
        }

        return $type?->creditCard ? self::METHOD_CARD : null;
    }

    /**
     * Akun GL pembayaran kartu: tipe QRIS/EDC memakai akun per warehouse (UDF OWHS); tipe lain memakai
     * akun GL kartu kredit SAP yang dipetakan.
     */
    private function account(Warehouse $warehouse, SalePayment $payment): ?string
    {
        return match (strtoupper((string) $payment->paymentType?->code)) {
            PaymentType::CODE_QRIS => $warehouse->qris_account,
            PaymentType::CODE_EDC => $warehouse->edc_account,
            default => $payment->paymentType?->creditCard?->gl_account,
        };
    }

    private function missingAccountMessage(Warehouse $warehouse, SalePayment $payment): string
    {
        return match (strtoupper((string) $payment->paymentType?->code)) {
            PaymentType::CODE_QRIS => "Akun QRIS warehouse {$warehouse->code} belum diisi (UDF U_SOL_QRIS_ACC di SAP B1).",
            PaymentType::CODE_EDC => "Akun EDC warehouse {$warehouse->code} belum diisi (UDF U_SOL_EDC_ACC di SAP B1).",
            default => "Akun GL kartu kredit {$payment->paymentType?->creditCard?->name} belum diisi di SAP B1 (menu Credit Cards).",
        };
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
     * Harga satuan efektif (setelah diskon promosi) dikirim sebagai `PriceAfterVAT` (harga termasuk PPN)
     * bila setting PPN inklusif aktif, atau `Price` (harga sebelum PPN) bila tidak. SAP menghitung
     * total baris dari harga x Quantity. `GrossPrice` sendirian diabaikan Service Layer (dibaca sebagai
     * Price, sehingga PPN ditambahkan lagi); diuji lewat Drafts di SAP demo.
     *
     * @return array<string, mixed>
     */
    private function invoiceLine(Sale $sale, SaleLine $line): array
    {
        $qty = (float) $line->qty;
        $inclusive = $this->company->ppn_enabled && $this->company->ppn_inclusive;
        // line_total sudah termasuk PPN pada mode inklusif; pada mode eksklusif line_total - tax_amount = nilai sebelum PPN.
        $amount = $inclusive
            ? (float) $line->line_total
            : (float) $line->line_total - (float) $line->tax_amount;

        $payload = [
            'ItemCode' => $line->sku_snapshot,
            'Quantity' => $qty,
            $inclusive ? 'PriceAfterVAT' : 'Price' => $qty > 0 ? round($amount / $qty, 6) : 0.0,
            'WarehouseCode' => $sale->store->warehouse->code,
        ];

        if (filled($line->tax_group_code)) {
            $payload['VatGroup'] = $line->tax_group_code;
        }

        // Profit center warehouse (UDF U_SOL_PRFC); dikosongkan bila belum diatur. Di Service Layer
        // field dimensi 1 bernama CostingCode/COGSCostingCode (OcrCode/CogsOcrCode adalah nama DI API).
        if (filled($sale->store->warehouse->profit_center)) {
            $payload['CostingCode'] = $sale->store->warehouse->profit_center;
            $payload['COGSCostingCode'] = $sale->store->warehouse->profit_center;
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
                'Remarks' => Str::limit("easyPOS {$sale->number}", 254, ''),
                'PaymentInvoices' => [[
                    'DocEntry' => $sale->sap_invoice_doc_entry,
                    'InvoiceType' => 'it_Invoice',
                    'SumApplied' => $docTotal,
                ]],
            ] + $this->paymentMeans($sale, $docTotal));

        $sale->forceFill([
            'sap_payment_obj_type' => self::OBJ_INCOMING_PAYMENT,
            'sap_payment_doc_num' => (string) $document['DocNum'],
            'sap_payment_doc_entry' => (int) $document['DocEntry'],
        ])->saveQuietly();
    }

    /**
     * Sarana pembayaran Incoming Payment: QRIS/EDC sebagai Credit Card, sisanya tunai
     * (CashSum = total invoice dikurangi QRIS/EDC, sehingga kembalian tunai tidak ikut tercatat).
     *
     * @return array<string, mixed>
     */
    private function paymentMeans(Sale $sale, float $docTotal): array
    {
        $warehouse = $sale->store->warehouse;
        $cards = $sale->payments->filter(fn (SalePayment $payment) => $this->method($payment) !== self::METHOD_CASH);
        $cashSum = round($docTotal - round((float) $cards->sum('amount'), 2), 2);

        if ($cashSum < -self::TOTAL_TOLERANCE) {
            throw new RuntimeException('Pembayaran QRIS/EDC melebihi total A/R Invoice; Incoming Payment ditahan untuk dicek.');
        }

        $means = [];

        if ($cashSum > self::TOTAL_TOLERANCE) {
            if (blank($warehouse->cash_account)) {
                throw new RuntimeException("Akun kas warehouse {$warehouse->code} belum diisi (UDF U_SOL_CASH_ACC di SAP B1).");
            }

            $means['CashAccount'] = $warehouse->cash_account;
            $means['CashSum'] = $cashSum;
        }

        if ($cards->isNotEmpty()) {
            $means['PaymentCreditCards'] = $cards
                ->map(fn (SalePayment $payment) => $this->creditCardLine($sale, $payment))
                ->values()
                ->all();
        }

        return $means;
    }

    /**
     * @return array<string, mixed>
     */
    private function creditCardLine(Sale $sale, SalePayment $payment): array
    {
        return [
            'CreditCard' => (int) $payment->paymentType->creditCard->code,
            'CreditCardNumber' => self::DEFAULT_CARD_NUMBER,
            'CardValidUntil' => $sale->business_date->copy()->addYear()->endOfMonth()->toDateString(),
            'VoucherNum' => Str::limit((string) $sale->number, 20, ''),
            'CreditSum' => round((float) $payment->amount, 2),
            'CreditCur' => $sale->currency_code ?: 'IDR',
            'CreditAcct' => (string) $this->account($sale->store->warehouse, $payment),
        ];
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
