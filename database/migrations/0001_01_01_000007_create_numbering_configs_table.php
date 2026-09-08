<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penomoran pindah ke tabel per tipe transaksi
        Schema::dropIfExists('transaction_sequences');

        Schema::table('company_details', function (Blueprint $table) {
            $table->dropColumn([
                'sales_number_prefix', 'sales_number_reset_period', 'sales_number_padding',
                'sales_number_next', 'sales_number_separator',
            ]);
        });

        Schema::create('numbering_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transaction_type', 60)->unique();
            $table->string('prefix', 20)->default('');
            $table->unsignedTinyInteger('padding')->default(4);
            $table->string('separator', 1)->default('/');
            $table->enum('reset_period', ['daily', 'monthly', 'yearly', 'never'])->default('monthly');
            $table->string('last_period', 20)->nullable();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_configs');

        Schema::table('company_details', function (Blueprint $table) {
            $table->string('sales_number_prefix', 20)->default('INV');
            $table->enum('sales_number_reset_period', ['daily', 'monthly', 'yearly', 'never'])->default('monthly');
            $table->unsignedTinyInteger('sales_number_padding')->default(4);
            $table->unsignedBigInteger('sales_number_next')->default(1);
            $table->string('sales_number_separator', 1)->default('/');
        });

        Schema::create('transaction_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('period', 20);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['type', 'period', 'store_id'], 'txn_seq_unique');
        });
    }
};