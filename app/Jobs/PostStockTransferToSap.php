<?php

namespace App\Jobs;

use App\Models\CompanyDetail;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferShipment;
use App\Support\Sap\SapStockTransferPosting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PostStockTransferToSap implements ShouldQueue
{
    use Queueable;

    /**
     * Percobaan ulang hanya dipakai pelepasan WithoutOverlapping; exception tetap tidak diulang (maxExceptions = 1).
     */
    public int $tries = 5;

    public int $maxExceptions = 1;

    /**
     * @param  class-string<StockTransferShipment|StockTransferReceipt>  $documentClass
     */
    public function __construct(public string $documentClass, public string $documentId) {}

    /**
     * Satu dokumen tidak diposting dua job sekaligus (antrean ulang saat job sebelumnya masih jalan).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->documentClass.':'.$this->documentId))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(): void
    {
        if (! in_array($this->documentClass, [StockTransferShipment::class, StockTransferReceipt::class], true)) {
            return;
        }

        $document = $this->documentClass::find($this->documentId);
        $company = $document ? CompanyDetail::find($document->company_id) : null;

        if (! $document || ! $company?->sapIntegrationActive() || $document->sap_post_status === $document::SAP_POSTED) {
            return;
        }

        (new SapStockTransferPosting($company))->post($document);
    }
}
