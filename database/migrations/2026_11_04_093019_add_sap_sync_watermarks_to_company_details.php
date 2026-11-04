<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delta SAP hanya mengambil data yang berubah: penanda waktu perubahan terakhir per objek
     * (dari UpdateDate + UpdateTime SAP sendiri) dan waktu rekonsiliasi saldo OITW penuh terakhir.
     */
    public function up(): void
    {
        Schema::table('company_details', function (Blueprint $table) {
            $table->json('sap_sync_watermarks')->nullable()->after('sap_last_synced_at');
            $table->timestamp('sap_stock_reconciled_at')->nullable()->after('sap_stock_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('company_details', function (Blueprint $table) {
            $table->dropColumn(['sap_sync_watermarks', 'sap_stock_reconciled_at']);
        });
    }
};
