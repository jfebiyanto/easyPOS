<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_shipment_lines', function (Blueprint $table) {
            $table->decimal('qty_returned', 15, 3)->default(0)->after('qty_received'); // akumulasi pengembalian
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_shipment_lines', function (Blueprint $table) {
            $table->dropColumn('qty_returned');
        });
    }
};
