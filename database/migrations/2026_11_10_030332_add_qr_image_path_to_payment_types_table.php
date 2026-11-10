<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gambar QR statis (mis. QRIS toko) yang ditampilkan di dialog bayar POS agar pelanggan tinggal memindai.
     */
    public function up(): void
    {
        Schema::table('payment_types', function (Blueprint $table) {
            $table->boolean('is_qr_payment')->default(false)->after('credit_card_id');
            $table->string('qr_image_path')->nullable()->after('credit_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_types', function (Blueprint $table) {
            $table->dropColumn(['is_qr_payment', 'qr_image_path']);
        });
    }
};
