<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer retail dari SAP B1 (BP dengan UDF U_SOL_RETAIL = 'Y'): non-member dan menjadi calon
     * pelanggan retail default gerai.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_retail')->default(false)->after('is_member');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_retail');
        });
    }
};
