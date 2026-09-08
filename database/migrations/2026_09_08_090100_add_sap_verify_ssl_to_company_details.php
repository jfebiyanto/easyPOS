<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_details', function (Blueprint $table) {
            $table->boolean('sap_verify_ssl')->default(true)->after('sap_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_details', function (Blueprint $table) {
            $table->dropColumn('sap_verify_ssl');
        });
    }
};