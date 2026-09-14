<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('code', 3);                    // Kode ISO 4217: IDR, USD, dst.
            $table->string('symbol', 10);                 // Simbol: Rp, $, €, dst.
            $table->boolean('has_rounding')->default(false);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        // Nominal pecahan per mata uang (dipakai juga saat hitung kas EOD).
        Schema::create('currency_denominations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('currency_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 15, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['currency_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_denominations');
        Schema::dropIfExists('currencies');
    }
};
