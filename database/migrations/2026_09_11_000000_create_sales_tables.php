<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('number', 60)->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->enum('origin', ['online', 'offline'])->default('online');
            $table->enum('sync_status', ['pending', 'synced'])->default('synced');
            $table->date('business_date');
            $table->enum('type', ['sale', 'return'])->default('sale');
            $table->foreignUuid('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['completed', 'void'])->default('completed');
            $table->enum('stock_review_status', ['none', 'pending', 'resolved'])->default('none');
            $table->text('review_reason')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('change_total', 15, 2)->default(0);
            $table->string('currency_code', 10)->default('IDR');
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'business_date']);
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku_snapshot', 40)->nullable();
            $table->string('name_snapshot');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('qty', 15, 3)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->enum('status', ['pending', 'confirmed', 'void'])->default('confirmed');
            $table->string('provider', 40)->nullable();
            $table->string('provider_ref', 120)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('number', 60)->nullable();
            $table->foreignUuid('original_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->string('refund_method', 30)->nullable();
            $table->boolean('receipt_verified')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_line_id')->nullable()->constrained('sale_lines')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('qty', 15, 3)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_name');
            $table->string('phone', 30)->nullable();
            $table->text('address');
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->enum('status', ['pending', 'shipped', 'delivered', 'cancelled'])->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        // Transaksi ditahan (hold/parked) — mendukung lanjut lintas perangkat.
        Schema::create('sale_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->foreignUuid('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_label')->nullable();
            $table->json('payload');
            $table->enum('status', ['held', 'finalized', 'cancelled'])->default('held');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_drafts');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
    }
};
