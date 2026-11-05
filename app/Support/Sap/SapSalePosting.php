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
 * - Pembayaran tunai = CashAccount/CashSum (akun kas warehouse). QRIS dan EDC dicatat sebagai
 *   Credit Card (PaymentCreditCards) dengan akun QRIS/EDC warehouse dan nomor kartu default.
 * - Baris invoice memakai profit center warehouse (CostingCode/COGSCostingCode di Service Layer) bila terisi, dan harga satuan di GrossPrice (PPN inklusif) atau Price (eksklusif).
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
        $sale->loadMissing(['lines', 'customer', 'store.warehouse', 'payments.paymentType']);
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
     * Setiap pembayaran harus bertipe CASH, QRIS, atau EDC dengan akun warehouse yang terisi;
     * QRIS/EDC harus sudah terkonfirmasi (pembayaran pending menahan posting).
     */
    private function assertPaymentsPostable(Sale $sale): void
    {
        $warehouse = $sale->store->warehouse;

        foreach ($sale->payments as $payment) {
            $method = $this->method($payment);

            if ($method === null) {
                $code = $payment->paymentType?->code ?? '-';
                throw new RuntimeException("Tipe pembayaran {$code} belum didukung posting SAP B1 (hanya CASH, QRIS, dan EDC).");
            }

            if ($method !== PaymentType::CODE_CASH && $payment->status !== 'confirmed') {
                throw new RuntimeException("Pembayaran {$method} belum terkonfirmasi; posting menunggu konfirmasi.");
            }

            if ($method === PaymentType::CODE_CASH) {
                continue;
            }

            if (blank($this->account($warehouse, $method))) {
                throw new RuntimeException("Akun {$method} warehouse {$warehouse->code} belum diisi (UDF {$this->udf($method)} di SAP B1).");
            }
        }

        if ($sale->payments->contains(fn (SalePayment $payment) => $this->method($payment) === PaymentType::CODE_CASH) && blank($warehouse->cash_account)) {
            throw new RuntimeException("Akun kas warehouse {$warehouse->code} belum diisi (UDF U_SOL_CASH_ACC di SAP B1).");
        }
    }

    /**
     * @return ?string CASH, QRIS, EDC, atau null bila tipe pembayaran tidak dikenali
     */
    private function method(SalePayment $payment): ?string
    {
        $code = strtoupper((string) $payment->paymentType?->code);

        return in_array($code, [PaymentType::CODE_CASH, PaymentType::CODE_QRIS, PaymentType::CODE_EDC], true) ? $code : null;
    }

    private function account(Warehouse $warehouse, string $method): ?string
    {
        return match ($method) {
            PaymentType::CODE_QRIS => $warehouse->qris_account,
            PaymentType::CODE_EDC => $warehouse->edc_account,
            default => $warehouse->cash_account,
        };
    }

    private function udf(string $method): string
    {
        return $method === PaymentType::CODE_QRIS ? 'U_SOL_QRIS_ACC' : 'U_SOL_EDC_ACC';
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
     * Harga satuan efektif (setelah diskon promosi) dikirim sebagai `GrossPrice` (harga termasuk PPN)
     * bila setting PPN inklusif aktif, atau `Price` (harga sebelum PPN) bila tidak. SAP menghitung
     * total baris dari harga x Quantity.
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
            $inclusive ? 'GrossPrice' : 'Price' => $qty > 0 ? round($amount / $qty, 6) : 0.0,
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
            ] + $this->paymentMeans($client, $sale, $docTotal));

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
    private function paymentMeans(SapServiceLayer $client, Sale $sale, float $docTotal): array
    {
        $warehouse = $sale->store->warehouse;
        $cards = $sale->payments->filter(fn (SalePayment $payment) => $this->method($payment) !== PaymentType::CODE_CASH);
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
                ->map(fn (SalePayment $payment) => $this->creditCardLine($client, $sale, $payment))
                ->values()
                ->all();
        }

        return $means;
    }

    /**
     * @return array<string, mixed>
     */
    private function creditCardLine(SapServiceLayer $client, Sale $sale, SalePayment $payment): array
    {
        $account = (string) $this->account($sale->store->warehouse, (string) $this->method($payment));

        return [
            'CreditCard' => $this->creditCardCode($client, $account),
            'CreditCardNumber' => self::DEFAULT_CARD_NUMBER,
            'CardValidUntil' => $sale->business_date->copy()->addYear()->endOfMonth()->toDateString(),
            'VoucherNum' => Str::limit((string) $sale->number, 20, ''),
            'CreditSum' => round((float) $payment->amount, 2),
            'CreditCur' => $sale->currency_code ?: 'IDR',
            'CreditAcct' => $account,
        ];
    }

    /**
     * Kode kartu kredit SAP (OCRC) yang akun GL-nya sama dengan akun QRIS/EDC warehouse.
     */
    private function creditCardCode(SapServiceLayer $client, string $account): int
    {
        $found = $client->find('CreditCards', [
            '$select' => 'CreditCardCode,GLAccount',
            '$filter' => "GLAccount eq '".str_replace("'", "''", $account)."'",
        ]);

        return (int) ($found['value'][0]['CreditCardCode']
            ?? throw new RuntimeException("Kartu kredit SAP B1 dengan akun {$account} belum dibuat (menu Credit Cards)."));
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
