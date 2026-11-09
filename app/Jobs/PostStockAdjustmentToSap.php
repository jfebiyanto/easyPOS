<?php

namespace App\Jobs;

use App\Models\CompanyDetail;
use App\Models\StockAdjustment;
use App\Support\Sap\SapStockAdjustmentPosting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PostStockAdjustmentToSap implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $adjustmentId) {}

    public function handle(): void
    {
        $adjustment = StockAdjustment::find($this->adjustmentId);
        $company = $adjustment ? CompanyDetail::find($adjustment->company_id) : null;

        if (! $adjustment || ! $company?->sapIntegrationActive() || ! $adjustment->isPosted() || $adjustment->sap_post_status === StockAdjustment::SAP_POSTED) {
            return;
        }

        (new SapStockAdjustmentPosting($company))->post($adjustment);
    }
}
