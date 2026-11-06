<?php

namespace App\Jobs;

use App\Models\CompanyDetail;
use App\Models\SapSyncRun;
use App\Support\Sap\SapImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSapImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public string $runId) {}

    public function handle(): void
    {
        $run = SapSyncRun::find($this->runId);
        $company = $run ? CompanyDetail::find($run->company_id) : null;

        if (! $run || ! $company || $run->status === SapSyncRun::STATUS_CANCELLED) {
            return;
        }

        set_time_limit(0);

        (new SapImportService($company, $run))->run();
    }

    public function failed(?\Throwable $e): void
    {
        SapSyncRun::whereKey($this->runId)->update([
            'status' => SapSyncRun::STATUS_FAILED,
            'finished_at' => now(),
            'error' => $e ? mb_substr($e->getMessage(), 0, 500) : 'Job failed.',
        ]);
    }
}
