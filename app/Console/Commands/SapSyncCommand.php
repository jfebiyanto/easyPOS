<?php

namespace App\Console\Commands;

use App\Models\CompanyDetail;
use App\Models\SapSyncRun;
use App\Support\Sap\SapSyncDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sap:sync {--force : Abaikan interval, jalankan sinkronisasi sekarang}')]
#[Description('Tarik data baru/berubah dari SAP B1 bila interval sinkronisasi sudah terlewati')]
class SapSyncCommand extends Command
{
    public function handle(): int
    {
        $company = CompanyDetail::query()->orderBy('created_at')->first();

        if (! $company || ! $company->sapIntegrationActive() || ! $company->sap_auto_sync) {
            return self::SUCCESS;
        }

        // Sinkronisasi terjadwal hanya berjalan setelah impor awal selesai.
        if (! $company->sap_initial_imported_at) {
            return self::SUCCESS;
        }

        $interval = max(1, (int) $company->sap_sync_interval_minutes);
        $due = ! $company->sap_last_synced_at
            || $company->sap_last_synced_at->copy()->addMinutes($interval)->isPast();

        if (! $due && ! $this->option('force')) {
            return self::SUCCESS;
        }

        $run = SapSyncDispatcher::start($company, SapSyncRun::MODE_DELTA, 'schedule');
        $this->info($run ? 'Sinkronisasi SAP B1 dijadwalkan.' : 'Sinkronisasi sebelumnya masih berjalan.');

        return self::SUCCESS;
    }
}
