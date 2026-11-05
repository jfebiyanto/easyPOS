<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Diskon SAP B1 (Period & Volume Discount dan Discount Groups) disimpan sebagai baris promosi
     * `source = sap` (hanya-baca) agar memakai mesin promosi yang sama. Hierarki promosi per perusahaan
     * menentukan sumber diskon mana yang menang bila beberapa berlaku pada satu baris.
     */
    public function up(): void
    {
        // Kolom `type` semula enum (check constraint); tipe baru `volume_price` perlu diizinkan.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE promotions DROP CONSTRAINT IF EXISTS promotions_type_check');
        }
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('type', 30)->default('discount_percent')->change();
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->string('source', 10)->default('manual')->after('type');
            $table->string('sap_ref', 100)->nullable()->after('source');
            $table->foreignUuid('brand_id')->nullable()->after('product_id')->constrained('brands')->nullOnDelete();
            $table->foreignUuid('product_group_id')->nullable()->after('brand_id')->constrained('product_groups')->nullOnDelete();
            $table->string('customer_scope', 20)->default('all')->after('product_group_id');
            $table->string('customer_ref', 50)->nullable()->after('customer_scope');
            $table->string('price_list_code', 30)->nullable()->after('customer_ref');
            $table->json('tiers')->nullable()->after('price_list_code');
            $table->string('group_key', 100)->nullable()->after('tiers');
            $table->string('group_relation', 30)->nullable()->after('group_key');

            $table->unique(['company_id', 'sap_ref']);
        });

        Schema::table('company_details', function (Blueprint $table) {
            $table->json('promotion_hierarchy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_details', function (Blueprint $table) {
            $table->dropColumn('promotion_hierarchy');
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'sap_ref']);
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('product_group_id');
            $table->dropColumn(['source', 'sap_ref', 'customer_scope', 'customer_ref', 'price_list_code', 'tiers', 'group_key', 'group_relation']);
        });
    }
};
