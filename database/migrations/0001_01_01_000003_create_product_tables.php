<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'code']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'code']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 40);
            $table->string('barcode', 60)->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 20)->default('PCS');
            $table->decimal('base_price', 15, 2)->default(0);
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'sku']);
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'code']);
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

        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
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
            $table->unique(['store_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_groups');
    }
};