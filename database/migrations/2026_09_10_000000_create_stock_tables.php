<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buku besar stok (append-only). Saldo = akumulasi qty per store+product.
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['opening', 'sale', 'sale_return', 'adjustment', 'transfer_out', 'transfer_in']);
            $table->decimal('qty', 15, 3); // bertanda: + masuk, - keluar
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->string('ref_type', 40)->nullable();
            $table->uuid('ref_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->index();

            $table->index(['store_id', 'product_id']);
            $table->index(['ref_type', 'ref_id']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('number', 60)->nullable();
            $table->date('date');
            $table->text('reason')->nullable();
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty_before', 15, 3)->default(0);
            $table->decimal('qty_after', 15, 3)->default(0);
            $table->decimal('delta', 15, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('number', 60)->nullable();
            $table->foreignUuid('from_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignUuid('to_store_id')->constrained('stores')->cascadeOnDelete();
            $table->enum('status', ['draft', 'requested', 'shipped', 'received', 'cancelled'])->default('draft');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty_requested', 15, 3)->default(0);
            $table->decimal('qty_shipped', 15, 3)->nullable();
            $table->decimal('qty_received', 15, 3)->nullable();
            $table->text('variance_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_ledger');
    }
};
