<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu Customer Group per perusahaan dapat menjadi nilai awal saat membuat customer baru.
     */
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
