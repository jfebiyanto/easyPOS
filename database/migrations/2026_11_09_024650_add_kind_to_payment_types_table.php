<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis tipe pembayaran: `cash` tidak perlu dipetakan ke Credit Card SAP B1, `custom` wajib dipetakan
     * (selama integrasi SAP aktif). Tipe berkode CASH yang sudah ada menjadi `cash`.
     */
    public function up(): void
    {
        Schema::table('payment_types', function (Blueprint $table) {
            $table->string('kind', 10)->default('custom')->after('code');
        });

        DB::table('payment_types')->whereRaw('upper(code) = ?', ['CASH'])->update(['kind' => 'cash']);
    }

    public function down(): void
    {
        Schema::table('payment_types', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
