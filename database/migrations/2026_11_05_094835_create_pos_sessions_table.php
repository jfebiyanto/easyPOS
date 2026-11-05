<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sesi POS per kasir: hanya satu yang aktif; mencatat browser dan IP untuk audit.
     * `session_hash` = hash sesi login (cookie) browser, jadi refresh tetap sesi yang sama.
     */
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('session_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 20)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
            $table->index(['company_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
