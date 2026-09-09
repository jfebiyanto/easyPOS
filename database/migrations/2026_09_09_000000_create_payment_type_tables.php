<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tipe pembayaran bersifat global (bukan milik satu store) karena
        // bisa dipakai lintas gerai lewat tabel pivot payment_type_store.
        Schema::create('payment_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('order_number')->default(1);
            $table->enum('count_type', ['summary', 'detail'])->default('summary');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_type_store', function (Blueprint $table) {
            $table->foreignUuid('payment_type_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->primary(['payment_type_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_type_store');
        Schema::dropIfExists('payment_types');
    }
};
