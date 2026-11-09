<?php

namespace App\Jobs;

use App\Models\CompanyDetail;
use App\Models\SalesReturn;
use App\Support\Sap\SapReturnPosting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PostReturnToSap implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $returnId) {}

    public function handle(): void
    {
        $return = SalesReturn::find($this->returnId);
        $company = $return ? CompanyDetail::find($return->company_id) : null;

        if (! $return || ! $company?->sapIntegrationActive() || $return->sap_post_status === SalesReturn::SAP_POSTED) {
            return;
        }

        (new SapReturnPosting($company))->post($return);
    }
}
