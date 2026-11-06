<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock Transfer Shipment: gudang pengirim ke gudang in-transit pengirim.
     */
    public function up(): void
    {
        Schema::create('stock_transfer_shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('number', 30)->nullable(); // diberikan saat Post
            $table->foreignUuid('from_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignUuid('to_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignUuid('from_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignUuid('transit_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete(); // disalin saat Post
            $table->foreignUuid('to_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('status', 10)->default('draft')->index();
            $table->text('note')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sap_post_status', 10)->nullable()->index();
            $table->text('sap_post_error')->nullable();
            $table->string('sap_doc_num', 20)->nullable();
            $table->unsignedInteger('sap_doc_entry')->nullable();
            $table->timestamp('sap_posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_shipments');
    }
};
