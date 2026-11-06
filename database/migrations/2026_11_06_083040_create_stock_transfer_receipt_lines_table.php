<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('receipt_id')->constrained('stock_transfer_receipts')->cascadeOnDelete();
            $table->foreignUuid('shipment_line_id')->constrained('stock_transfer_shipment_lines')->cascadeOnDelete();
            $table->decimal('qty', 15, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_receipt_lines');
    }
};
