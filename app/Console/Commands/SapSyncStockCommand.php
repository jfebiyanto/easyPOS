<?php

namespace App\Console\Commands;

use App\Models\CompanyDetail;
use App\Models\SapSyncRun;
use App\Support\Sap\SapServiceLayer;
use App\Support\Sap\SapStockMutationService;
use App\Support\Sap\SapSyncDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

#[Signature('sap:sync-stock')]
#[Description('Tarik mutasi stok baru dari SAP B1 (OIVL) ke buku besar warehouse easyPOS')]
class SapSyncStockCommand extends Command
{
    /**
     * Jeda minimum antar-sinkronisasi master yang dipicu oleh mutasi yang tertahan.
     */
    private const MASTER_RETRY_MINUTES = 10;

    public function handle(): int
    {
        $company = CompanyDetail::query()->orderBy('created_at')->first();

        if (! $company || ! $company->sapIntegrationActive() || ! $company->sap_auto_sync || ! $company->sap_initial_imported_at) {
            return self::SUCCESS;
        }

        // Impor/sinkronisasi master yang sedang berjalan juga menyalin mutasi stok.
        if (SapSyncDispatcher::activeRun($company)) {
            return self::SUCCESS;
        }

        $client = null;

        try {
            $client = new SapServiceLayer($company);
            $client->login();
            $result = (new SapStockMutationService($company))->sync($client);
        } catch (Throwable $e) {
            $company->forceFill(['sap_stock_sync_error' => Str::limit($e->getMessage(), 500)])->save();
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $client?->logout();
        }

        if ($result['busy']) {
            return self::SUCCESS;
        }

        $this->info("Mutasi stok: {$result['created']} baru, {$result['skipped']} sudah ada.");

        if ($result['halted'] !== null) {
            $this->warn($result['halted']);
            $this->syncMasterData($company);
        }

        return self::SUCCESS;
    }

    /**
     * Item/warehouse baru di SAP: tarik master data sekarang tanpa menunggu interval delta.
     */
    private function syncMasterData(CompanyDetail $company): void
    {
        $recentlyStarted = SapSyncRun::where('company_id', $company->id)
            ->where('created_at', '>=', now()->subMinutes(self::MASTER_RETRY_MINUTES))
            ->exists();

        if (! $recentlyStarted) {
            SapSyncDispatcher::start($company, SapSyncRun::MODE_DELTA, 'stock');
        }
    }
}
