<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun kas gerai dari UDF U_SOL_CASH_ACC (OWHS); penanda sumber customer & customer group
     * (customer manual dikirim ke SAP, customer dari SAP hanya-baca) dan status sinkronnya.
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('cash_account', 25)->nullable()->after('profit_center');
        });

        Schema::table('customer_groups', function (Blueprint $table) {
            $table->string('source', 10)->default('manual')->after('name');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('source', 10)->default('manual')->after('customer_group_id');
            $table->string('sap_sync_status', 10)->nullable()->after('source');
            $table->text('sap_sync_error')->nullable()->after('sap_sync_status');
            $table->timestamp('sap_synced_at')->nullable()->after('sap_sync_error');
        });

        $this->markRowsCreatedBySapImport();
    }

    /**
     * Data lama: baris yang dibuat selama impor/sinkronisasi SAP yang berhasil berasal dari SAP.
     */
    private function markRowsCreatedBySapImport(): void
    {
        $runs = DB::table('sap_sync_runs')
            ->where('status', 'success')
            ->whereNotNull('started_at')
            ->get(['company_id', 'started_at', 'finished_at']);

        foreach ($runs as $run) {
            foreach (['customers', 'customer_groups'] as $table) {
                DB::table($table)
                    ->where('company_id', $run->company_id)
                    ->whereBetween('created_at', [$run->started_at, $run->finished_at ?? now()])
                    ->update(['source' => 'sap']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['source', 'sap_sync_status', 'sap_sync_error', 'sap_synced_at']);
        });

        Schema::table('customer_groups', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('cash_account');
        });
    }
};
