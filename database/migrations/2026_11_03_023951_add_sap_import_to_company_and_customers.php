<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_details', function (Blueprint $table) {
            $table->unsignedInteger('sap_sync_interval_minutes')->default(30);
            $table->boolean('sap_auto_sync')->default(true);
            $table->timestamp('sap_last_synced_at')->nullable();
            $table->timestamp('sap_initial_imported_at')->nullable();
        });

        Schema::create('customer_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignUuid('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
        });

        Schema::create('sap_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('mode', 10);      // initial | delta
            $table->string('trigger', 10);   // manual | schedule
            $table->string('status', 10);    // queued | running | success | failed
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_sync_runs');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_group_id');
        });

        Schema::dropIfExists('customer_groups');

        Schema::table('company_details', function (Blueprint $table) {
            $table->dropColumn(['sap_sync_interval_minutes', 'sap_auto_sync', 'sap_last_synced_at', 'sap_initial_imported_at']);
        });
    }
};
