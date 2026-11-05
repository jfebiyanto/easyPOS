<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak pembatalan transaksi untuk pengawasan: batal sebelum bayar (F9) dan void penjualan. Nama gerai, kasir,
     * dan pelanggan disalin sebagai label agar laporan tetap utuh walau datanya kelak diubah atau dihapus.
     */
    public function up(): void
    {
        Schema::create('pos_cancellations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignUuid('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('store_label')->nullable();
            $table->foreignUuid('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cashier_name')->nullable();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_label')->nullable();
            $table->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('sale_number', 60)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->json('items')->nullable();
            $table->timestamp('occurred_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 120)->nullable();
            $table->foreignUuid('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'occurred_at']);
            $table->index(['store_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cancellations');
    }
};
