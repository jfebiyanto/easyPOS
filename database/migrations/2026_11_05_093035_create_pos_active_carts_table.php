<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keranjang POS yang sedang berjalan, satu per kasir, disimpan otomatis agar tidak hilang saat
     * refresh, tab tertutup, atau login ulang. Dihapus saat penjualan selesai, ditahan, atau dibatalkan.
     */
    public function up(): void
    {
        Schema::create('pos_active_carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->json('payload');
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('gross_total', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'cashier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_active_carts');
    }
};
