<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->unsignedInteger('buy_qty')->nullable()->after('min_qty');
            $table->unsignedInteger('get_qty')->nullable()->after('buy_qty');
            $table->foreignUuid('get_product_id')->nullable()->after('get_qty')->constrained('products')->nullOnDelete();
            $table->unsignedInteger('max_applications')->nullable()->after('get_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('get_product_id');
            $table->dropColumn(['buy_qty', 'get_qty', 'max_applications']);
        });
    }
};
