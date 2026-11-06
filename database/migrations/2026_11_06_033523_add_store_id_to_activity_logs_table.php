<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gerai tempat aksi dilakukan (snapshot gerai user saat itu), agar Store Manager hanya melihat log gerainya.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignUuid('store_id')->nullable()->after('company_id')->constrained('stores')->nullOnDelete();
            $table->index(['store_id', 'created_at']);
        });

        DB::statement('UPDATE activity_logs SET store_id = (SELECT users.store_id FROM users WHERE users.id = activity_logs.actor_id) WHERE actor_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
