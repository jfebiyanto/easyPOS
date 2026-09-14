<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('transaction_type', 60);
            $table->string('prefix', 20)->default('');
            $table->unsignedTinyInteger('padding')->default(4);
            $table->string('separator', 1)->default('/');
            $table->enum('reset_period', ['daily', 'monthly', 'yearly', 'never'])->default('monthly');
            $table->string('last_period', 20)->nullable();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'transaction_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_configs');
    }
};
