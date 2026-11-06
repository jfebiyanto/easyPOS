<?php

namespace App\Jobs;

use App\Models\CompanyDetail;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferShipment;
use App\Support\Sap\SapStockTransferPosting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PostStockTransferToSap implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  class-string<StockTransferShipment|StockTransferReceipt>  $documentClass
     */
    public function __construct(public string $documentClass, public string $documentId) {}

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
