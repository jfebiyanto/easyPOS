<?php

namespace App\Jobs;

use App\Models\CompanyDetail;
use App\Models\Sale;
use App\Support\Sap\SapSalePosting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PostSaleToSap implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $saleId) {}

    public function handle(): void
    {
        $sale = Sale::find($this->saleId);
        $company = $sale ? CompanyDetail::find($sale->company_id) : null;

        if (! $sale || ! $company?->sapIntegrationActive() || $sale->sap_post_status === Sale::SAP_POSTED) {
            return;
        }

        (new SapSalePosting($company))->post($sale);
    }
}
