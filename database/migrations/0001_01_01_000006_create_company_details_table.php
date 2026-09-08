<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('npwp_number', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('ppn_enabled')->default(false);
            $table->decimal('ppn_rate', 5, 2)->default(11.00);
            // Konfigurasi penomoran transaksi penjualan
            $table->string('sales_number_prefix', 20)->default('INV');
            $table->enum('sales_number_reset_period', ['daily', 'monthly', 'yearly', 'never'])->default('monthly');
            $table->unsignedTinyInteger('sales_number_padding')->default(4);
            $table->unsignedBigInteger('sales_number_next')->default(1);
            $table->string('sales_number_separator', 1)->default('/');
            $table->timestamps();
        });

        Schema::create('transaction_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 30); // sales, dsb.
            $table->string('period', 20); // sesuai reset period: YYYYMMDD / YYYYMM / YYYY / ALL
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['type', 'period', 'store_id'], 'txn_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_sequences');
        Schema::dropIfExists('company_details');
    }
};