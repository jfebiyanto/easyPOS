<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_shipment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shipment_id')->constrained('stock_transfer_shipments')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('qty', 15, 3); // dikirim
            $table->decimal('qty_received', 15, 3)->default(0); // akumulasi penerimaan
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_shipment_lines');
    }
};
