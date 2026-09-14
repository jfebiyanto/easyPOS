<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_closures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('sales_total', 15, 2)->default(0);
            $table->decimal('cash_expected', 15, 2)->default(0);
            $table->decimal('cash_counted', 15, 2)->default(0);
            $table->decimal('cash_variance', 15, 2)->default(0);
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'business_date']);
        });

        Schema::create('store_closure_denominations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_closure_id')->constrained()->cascadeOnDelete();
            $table->decimal('denomination_value', 15, 2);
            $table->unsignedInteger('count')->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_closure_denominations');
        Schema::dropIfExists('store_closures');
    }
};
