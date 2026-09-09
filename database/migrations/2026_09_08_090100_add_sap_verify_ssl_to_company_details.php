<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lewati bila kolom sudah ada (migrasi idempoten).
        if (Schema::hasColumn('company_details', 'sap_verify_ssl')) {
            return;
        }

        Schema::table('company_details', function (Blueprint $table) {
            $table->boolean('sap_verify_ssl')->default(true)->after('sap_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_details', 'sap_verify_ssl')) {
            return;
        }

        Schema::table('company_details', function (Blueprint $table) {
            $table->dropColumn('sap_verify_ssl');
        });
    }
};