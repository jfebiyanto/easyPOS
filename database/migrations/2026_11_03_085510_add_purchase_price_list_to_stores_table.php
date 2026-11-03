<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu gerai punya satu pricelist jual (pivot store_price_lists, is_default)
     * dan satu pricelist beli (kolom ini).
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignUuid('purchase_price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_price_list_id');
        });
    }
};
