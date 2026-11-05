<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun pembayaran QRIS dan EDC gerai dari UDF U_SOL_QRIS_ACC dan U_SOL_EDC_ACC pada Warehouse (OWHS),
     * dipakai sebagai akun kartu kredit pada Incoming Payment SAP B1.
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('qris_account', 25)->nullable()->after('cash_account');
            $table->string('edc_account', 25)->nullable()->after('qris_account');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['qris_account', 'edc_account']);
        });
    }
};
