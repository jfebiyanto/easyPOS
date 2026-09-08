<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Model User memakai UUID (HasUuid7) — ubah morphs tokenable ke UUID.
        // Deployment baru: tabel token kosong, jadi konversi aman.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE uuid USING NULL::uuid');

            return;
        }

        Schema::table('personal_access_tokens', function ($table) {
            $table->uuid('tokenable_id')->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE bigint USING 0');

            return;
        }

        Schema::table('personal_access_tokens', function ($table) {
            $table->unsignedBigInteger('tokenable_id')->change();
        });
    }
};