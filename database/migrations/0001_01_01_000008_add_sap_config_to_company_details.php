<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_details', function (Blueprint $table) {
            $table->boolean('sap_enabled')->default(false)->after('ppn_rate');
            $table->string('sap_endpoint_url', 255)->nullable()->after('sap_enabled');
            $table->string('sap_db_name', 60)->nullable()->after('sap_endpoint_url');
            $table->string('sap_username', 60)->nullable()->after('sap_db_name');
            $table->text('sap_password')->nullable()->after('sap_username');
        });
    }

    public function down(): void
    {
        Schema::table('company_details', function (Blueprint $table) {
            $table->dropColumn(['sap_enabled', 'sap_endpoint_url', 'sap_db_name', 'sap_username', 'sap_password']);
        });
    }
};