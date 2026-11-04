<?php

namespace App\Support\Sap;

use App\Jobs\PushCustomerToSap;
use App\Models\CompanyDetail;
use App\Models\Customer;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Kirim customer yang dibuat di easyPOS ke SAP B1 sebagai BusinessPartner (Customer).
 *
 * - CardCode dibuat SAP dari numbering series BP Customer default (harus otomatis), lalu
 *   disimpan sebagai `customers.code`.
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
        $series = $client->create('SeriesService_GetDefaultSeries', self::SERIES_PARAMS);

        if (($series['IsManual'] ?? 'tYES') === 'tYES') {
            throw new RuntimeException('Numbering series Business Partner Customer di SAP B1 masih manual. Siapkan series otomatis dan jadikan default agar CardCode dibuat SAP.');
        }

        $created = $client->create('BusinessPartners', $this->payload($customer) + [
            'CardType' => 'cCustomer',
            'Series' => $series['Series'],
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
