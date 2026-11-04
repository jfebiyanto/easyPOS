<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mutasi stok SAP B1 (OIVL) disalin baris per baris ke buku besar warehouse; on_hand
     * diturunkan dari buku besar. Saldo OITW disimpan terpisah (sap_on_hand) untuk rekonsiliasi.
     */
    public function up(): void
    {
        Schema::table('warehouse_stock_ledgers', function (Blueprint $table) {
            $table->unsignedBigInteger('sap_trans_seq')->nullable()->after('note');
            $table->integer('sap_trans_type')->nullable()->after('sap_trans_seq');
            $table->integer('sap_doc_entry')->nullable()->after('sap_trans_type');
            $table->string('sap_doc_num', 20)->nullable()->after('sap_doc_entry');
            $table->integer('sap_doc_line')->nullable()->after('sap_doc_num');
            $table->date('doc_date')->nullable()->after('sap_doc_line');

            $table->unique(['company_id', 'sap_trans_seq']);
        });

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->decimal('sap_on_hand', 15, 3)->nullable()->after('avg_price');
            $table->timestamp('sap_checked_at')->nullable()->after('sap_on_hand');
        });

        Schema::table('company_details', function (Blueprint $table) {
            $table->timestamp('sap_stock_synced_at')->nullable()->after('sap_initial_imported_at');
            $table->text('sap_stock_sync_error')->nullable()->after('sap_stock_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_details', function (Blueprint $table) {
            $table->dropColumn(['sap_stock_synced_at', 'sap_stock_sync_error']);
        });

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->dropColumn(['sap_on_hand', 'sap_checked_at']);
        });

        Schema::table('warehouse_stock_ledgers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'sap_trans_seq']);
            $table->dropColumn(['sap_trans_seq', 'sap_trans_type', 'sap_doc_entry', 'sap_doc_num', 'sap_doc_line', 'doc_date']);
        });
    }
};
