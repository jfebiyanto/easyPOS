<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buku besar stok per warehouse (append-only). Kerangka untuk pergerakan stok:
     * warehouse_stocks.on_hand adalah saldo, baris di sini adalah riwayat perubahannya.
     */
    public function up(): void
    {
        Schema::create('warehouse_stock_ledgers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 30);
            $table->decimal('qty', 15, 3); // bertanda: + masuk, - keluar
            $table->decimal('balance_after', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->string('ref_type', 40)->nullable();
            $table->uuid('ref_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->index();

            $table->index(['warehouse_id', 'product_id']);
            $table->index(['ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_ledgers');
    }
};
