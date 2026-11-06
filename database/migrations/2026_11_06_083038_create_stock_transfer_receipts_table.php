<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock Transfer Receipt: gudang in-transit pengirim ke gudang store penerima.
     */
    public function up(): void
    {
        Schema::create('stock_transfer_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('shipment_id')->constrained('stock_transfer_shipments')->cascadeOnDelete();
            $table->string('number', 30)->nullable();
            $table->foreignUuid('store_id')->constrained('stores')->cascadeOnDelete(); // penerima
            $table->text('note')->nullable();
            $table->timestamp('received_at')->nullable();
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
        Schema::dropIfExists('stock_transfer_receipts');
    }
};
