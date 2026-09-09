<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolom sudah ditambahkan oleh 0001_01_01_000008_add_sap_config_to_company_details,
        // jadi lewati bila sudah ada (migrasi idempoten).
        if (Schema::hasColumn('company_details', 'sap_enabled')) {
            return;
        }

        Schema::table('company_details', function (Blueprint $table) {
            $table->boolean('sap_enabled')->default(false)->after('ppn_rate');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_details', 'sap_enabled')) {
            return;
        }

        Schema::table('company_details', function (Blueprint $table) {
            $table->dropColumn('sap_enabled');
        });
    }
};