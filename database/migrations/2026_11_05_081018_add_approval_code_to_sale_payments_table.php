<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kode approval EDC/QRIS dari penyedia pembayaran. Hanya dicatat di easyPOS, tidak dikirim ke SAP.
     */
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('approval_code', 50)->nullable()->after('provider_ref');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn('approval_code');
        });
    }
};
