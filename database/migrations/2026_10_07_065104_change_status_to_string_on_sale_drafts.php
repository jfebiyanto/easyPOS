<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status "resumed" menandai hold yang sedang dilanjutkan di keranjang kasir
 * (belum jadi penjualan), sehingga enum lama perlu diganti string.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table sale_drafts drop constraint if exists sale_drafts_status_check');
        }

        Schema::table('sale_drafts', function (Blueprint $table) {
            $table->string('status', 20)->default('held')->change();
        });
    }

    public function down(): void
    {
        DB::table('sale_drafts')->where('status', 'resumed')->update(['status' => 'held']);

        Schema::table('sale_drafts', function (Blueprint $table) {
            $table->enum('status', ['held', 'finalized', 'cancelled'])->default('held')->change();
        });
    }
};
