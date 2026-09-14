<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satuan (unit of measure) — global per company.
        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        // Katalog global per company (bukan per store).
        Schema::create('product_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('product_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('unit_id')->constrained()->restrictOnDelete();
            $table->string('sku', 40);
            $table->string('barcode', 60)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('base_price', 15, 2)->default(0);
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->boolean('track_stock')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'barcode']);
        });

        // Pricelist: company-level (store_id null) atau store-level.
        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        // Assignment pricelist ke store.
        Schema::create('store_price_lists', function (Blueprint $table) {
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('price_list_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->primary(['store_id', 'price_list_id']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->unsignedInteger('min_qty')->default(1);
            $table->timestamps();

            $table->unique(['price_list_id', 'product_id', 'min_qty'], 'pli_unique');
        });

        // Promosi (di luar MVP; tabel dipertahankan untuk kompatibilitas).
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->enum('type', ['discount_percent', 'discount_amount', 'special_price', 'buy_x_get_y'])->default('discount_percent');
            $table->decimal('value', 15, 2)->default(0);
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('min_qty')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('store_price_lists');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_groups');
        Schema::dropIfExists('units');
    }
};
