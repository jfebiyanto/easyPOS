<?php

namespace App\Support\Sap;

use App\Jobs\RunSapImport;
use App\Models\CompanyDetail;
use App\Models\SapSyncRun;
use Illuminate\Support\Facades\DB;

class SapSyncDispatcher
{
    /**
     * Run yang berstatus queued/running lebih lama dari ini dianggap macet.
     */
    private const STALE_AFTER_HOURS = 3;

    /**
     * Buat run baru dan antrekan job. Mengembalikan null bila masih ada run yang berjalan.
     */
    public static function start(CompanyDetail $company, string $mode, string $trigger): ?SapSyncRun
    {
        if (self::activeRun($company)) {
            return null;
        }

        $run = SapSyncRun::create([
            'company_id' => $company->id,
            'mode' => $mode,
            'trigger' => $trigger,
            'status' => SapSyncRun::STATUS_QUEUED,
        ]);

        RunSapImport::dispatch($run->id);

        return $run;
    }

    /**
     * Batalkan run yang sedang antre/berjalan dan buang job-nya dari antrean (driver database).
     * Run yang sedang berjalan berhenti pada pemeriksaan berikutnya.
     */
    public static function cancel(CompanyDetail $company): ?SapSyncRun
    {
        $run = self::activeRun($company);
        if (! $run) {
            return null;
        }

        $run->update([
            'status' => SapSyncRun::STATUS_CANCELLED,
            'finished_at' => now(),
            'error' => 'Dibatalkan oleh pengguna.',
        ]);

        if (config('queue.default') === 'database') {
            DB::table(config('queue.connections.database.table', 'jobs'))
                ->where('payload', 'like', '%'.$run->id.'%')
                ->whereNull('reserved_at')
                ->delete();
        }

        return $run;
    }

    public static function activeRun(CompanyDetail $company): ?SapSyncRun
    {
        return SapSyncRun::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [SapSyncRun::STATUS_QUEUED, SapSyncRun::STATUS_RUNNING])
            ->where('created_at', '>=', now()->subHours(self::STALE_AFTER_HOURS))
            ->latest()
            ->first();
    }
}
