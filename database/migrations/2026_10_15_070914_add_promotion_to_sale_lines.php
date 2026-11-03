<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->foreignUuid('promotion_id')->nullable()->after('discount')->constrained('promotions')->nullOnDelete();
            $table->string('promotion_name_snapshot')->nullable()->after('promotion_id');
            $table->decimal('promotion_discount', 15, 2)->default(0)->after('promotion_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn(['promotion_name_snapshot', 'promotion_discount']);
        });
    }
};
