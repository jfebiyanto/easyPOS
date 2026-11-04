<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status posting penjualan POS ke SAP B1 (A/R Invoice + Incoming Payment) beserta
     * referensi dokumennya. ObjType + DocNum ditampilkan; DocEntry hanya kunci teknis.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('sap_post_status', 10)->nullable()->index()->after('void_reason');
            $table->text('sap_post_error')->nullable()->after('sap_post_status');
            $table->integer('sap_invoice_obj_type')->nullable()->after('sap_post_error');
            $table->string('sap_invoice_doc_num', 20)->nullable()->after('sap_invoice_obj_type');
            $table->integer('sap_invoice_doc_entry')->nullable()->after('sap_invoice_doc_num');
            $table->integer('sap_payment_obj_type')->nullable()->after('sap_invoice_doc_entry');
            $table->string('sap_payment_doc_num', 20)->nullable()->after('sap_payment_obj_type');
            $table->integer('sap_payment_doc_entry')->nullable()->after('sap_payment_doc_num');
            $table->timestamp('sap_posted_at')->nullable()->after('sap_payment_doc_entry');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['sap_post_status']);
            $table->dropColumn([
                'sap_post_status', 'sap_post_error',
                'sap_invoice_obj_type', 'sap_invoice_doc_num', 'sap_invoice_doc_entry',
                'sap_payment_obj_type', 'sap_payment_doc_num', 'sap_payment_doc_entry',
                'sap_posted_at',
            ]);
        });
    }
};
