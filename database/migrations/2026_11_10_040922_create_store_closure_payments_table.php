<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konfirmasi EOD per tipe pembayaran: nominal yang diharapkan saat tipe itu dikonfirmasi.
     */
    public function up(): void
    {
        Schema::create('store_closure_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_closure_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_code', 30);
            $table->string('kind', 10);
            $table->decimal('expected_amount', 15, 2)->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_closure_payments');
    }
};
