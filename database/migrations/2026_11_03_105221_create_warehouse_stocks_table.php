<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saldo inventory per produk per warehouse, seperti OITW di SAP B1.
     * Saat ini hanya saldo hasil impor; pergerakan stok menyusul.
     */
    public function up(): void
    {
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('on_hand', 15, 3)->default(0);   // OnHand / InStock
            $table->decimal('committed', 15, 3)->default(0); // IsCommited
            $table->decimal('on_order', 15, 3)->default(0);  // OnOrder / Ordered
            $table->decimal('avg_price', 15, 2)->default(0); // AvgPrice
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
