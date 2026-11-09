<?php

namespace App\Support\Sap;

use App\Jobs\PushCustomerToSap;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Kirim customer yang dibuat di easyPOS ke SAP B1 sebagai BusinessPartner (Customer).
 *
 * - CardCode dibuat SAP dari numbering series BP Customer retail (UDF U_SOL_RETAIL = 'Y' pada
 *   series, harus otomatis), lalu disimpan sebagai `customers.code`. BP ditandai retail.
 * - Idempoten: sebelum membuat BP, cari BP dengan U_SOL_TRKY = UUID customer.
 * - Setelah tersinkron, perubahan field SAP (nama, HP, email, alamat, grup) dikirim lewat PATCH.
 */
class SapCustomerSync
{
    /** BP subtype Customer pada SeriesService (ObjectCode 2 = Business Partner). */
    private const SERIES_PARAMS = ['DocumentTypeParams' => ['Document' => '2', 'DocumentSubType' => 'C']];

    /**
     * Antrekan pengiriman bila integrasi aktif dan customer dikelola di easyPOS.
     */
    public static function queue(Customer $customer): void
    {
        $company = CompanyDetail::find($customer->company_id);

        if (! $company?->sapIntegrationActive() || $customer->isFromSap()) {
            return;
        }

        $customer->forceFill(['sap_sync_status' => Customer::SYNC_PENDING, 'sap_sync_error' => null])->saveQuietly();

        PushCustomerToSap::dispatch($customer->id)->afterCommit();
    }

    public function __construct(private readonly CompanyDetail $company) {}

    public function push(Customer $customer): void
    {
        $client = new SapServiceLayer($this->company);

        try {
            $client->login();

            if ($customer->sap_synced_at === null) {
                $cardCode = $this->findCardCode($client, $customer) ?? $this->createBusinessPartner($client, $customer);
                $customer->forceFill(['code' => $cardCode]);
            } else {
                $client->update("BusinessPartners('".rawurlencode((string) $customer->code)."')", $this->payload($customer));
            }

            $customer->forceFill([
                'sap_sync_status' => Customer::SYNC_SYNCED,
                'sap_sync_error' => null,
                'sap_synced_at' => now(),
            ])->saveQuietly();

            // Penjualan yang menunggu CardCode customer ini dikirim sekarang.
            Sale::where('customer_id', $customer->id)
                ->whereIn('sap_post_status', [Sale::SAP_PENDING, Sale::SAP_FAILED])
                ->whereNull('sap_invoice_doc_entry')
                ->each(fn (Sale $sale) => SapSalePosting::queue($sale));
        } catch (Throwable $e) {
            $customer->forceFill([
                'sap_sync_status' => Customer::SYNC_FAILED,
                'sap_sync_error' => Str::limit($e->getMessage(), 500),
            ])->saveQuietly();
        } finally {
            $client->logout();
        }
    }

    private function findCardCode(SapServiceLayer $client, Customer $customer): ?string
    {
        $existing = $client->find('BusinessPartners', [
            '$select' => 'CardCode',
            '$filter' => "U_SOL_TRKY eq '{$customer->id}'",
        ]);

        return $existing['value'][0]['CardCode'] ?? null;
    }

    private function createBusinessPartner(SapServiceLayer $client, Customer $customer): string
    {
        $series = collect($client->create('SeriesService_GetDocumentSeries', self::SERIES_PARAMS)['value'] ?? [])
            ->first(fn (array $row) => ($row['U_SOL_RETAIL'] ?? 'N') === 'Y'
                && ($row['IsManual'] ?? 'tYES') === 'tNO'
                && ($row['Locked'] ?? 'tNO') === 'tNO');

        if ($series === null) {
            throw new RuntimeException("Numbering series Business Partner Customer retail (UDF U_SOL_RETAIL = 'Y', otomatis, tidak dikunci) belum ada di SAP B1.");
        }

        $created = $client->create('BusinessPartners', $this->payload($customer) + [
            'CardType' => 'cCustomer',
            'Series' => $series['Series'],
            'U_SOL_RETAIL' => 'N',
            'U_SOL_FRST' => 'Y',
            'U_SOL_TRKY' => $customer->id,
        ]);

        return (string) ($created['CardCode'] ?? throw new RuntimeException('SAP B1 tidak mengembalikan CardCode.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Customer $customer): array
    {
        $payload = [
            'CardName' => Str::limit($customer->name, 100, ''),
            'Cellular' => $customer->phone,
            'EmailAddress' => $customer->email,
            'Address' => $customer->address !== null ? Str::limit($customer->address, 100, '') : null,
        ];

        $groupCode = $customer->customerGroup()->where('source', 'sap')->value('code');
        if ($groupCode !== null) {
            $payload['GroupCode'] = (int) $groupCode;
        }

        return $payload;
    }
}
