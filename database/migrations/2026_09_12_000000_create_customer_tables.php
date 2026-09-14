<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('min_points', 15, 2)->default(0);
            $table->decimal('earn_amount_per_point', 15, 2)->default(10000);
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('code', 30)->nullable();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_member')->default(true);
            $table->foreignUuid('member_level_id')->nullable()->constrained('member_levels')->nullOnDelete();
            $table->decimal('points_balance', 15, 2)->default(0);
            $table->timestamp('joined_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'phone']);
        });

        Schema::create('loyalty_config', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->decimal('min_spend', 15, 2)->default(0);
            $table->enum('rounding', ['none', 'down', 'up', 'nearest'])->default('down');
            $table->unsignedInteger('expiry_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('company_id');
        });

        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['earn', 'return', 'adjust', 'expire', 'redeem']);
            $table->decimal('points', 15, 2);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->string('ref_type', 40)->nullable();
            $table->uuid('ref_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->index();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger');
        Schema::dropIfExists('loyalty_config');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('member_levels');
    }
};
