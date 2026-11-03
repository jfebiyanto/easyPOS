<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak pembukaan ulang EOD terakhir (riwayat lengkap tercatat di activity log).
     */
    public function up(): void
    {
        Schema::table('store_closures', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable()->after('closed_by');
            $table->foreignUuid('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable()->after('reopened_by');
        });
    }

    public function down(): void
    {
        Schema::table('store_closures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['reopened_at', 'reopen_reason']);
        });
    }
};
