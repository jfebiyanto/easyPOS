<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semua warehouse SAP diimpor; hanya warehouse retail (U_SOL_RETAIL = 'Y') yang dapat
     * dipakai gerai. Data lama hanya berisi warehouse retail, jadi default true.
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('is_retail')->default(true)->after('profit_center');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('is_retail');
        });
    }
};
