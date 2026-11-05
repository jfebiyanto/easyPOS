<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pemetaan Tipe Pembayaran ke kartu kredit SAP B1 (dipakai pembayaran non-tunai).
     */
    public function up(): void
    {
        Schema::table('payment_types', function (Blueprint $table) {
            $table->foreignUuid('credit_card_id')->nullable()->after('count_type')->constrained('credit_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_card_id');
        });
    }
};
