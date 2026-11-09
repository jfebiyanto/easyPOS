<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status posting retur penjualan ke SAP B1 (A/R Credit Memo + Outgoing Payment tunai)
     * beserta referensi dokumennya. ObjType + DocNum ditampilkan; DocEntry hanya kunci teknis.
     */
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('sap_post_status', 10)->nullable()->index();
            $table->text('sap_post_error')->nullable();
            $table->integer('sap_credit_memo_obj_type')->nullable();
            $table->string('sap_credit_memo_doc_num', 20)->nullable();
            $table->integer('sap_credit_memo_doc_entry')->nullable();
            $table->integer('sap_refund_obj_type')->nullable();
            $table->string('sap_refund_doc_num', 20)->nullable();
            $table->integer('sap_refund_doc_entry')->nullable();
            $table->timestamp('sap_posted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropIndex(['sap_post_status']);
            $table->dropColumn([
                'sap_post_status', 'sap_post_error',
                'sap_credit_memo_obj_type', 'sap_credit_memo_doc_num', 'sap_credit_memo_doc_entry',
                'sap_refund_obj_type', 'sap_refund_doc_num', 'sap_refund_doc_entry',
                'sap_posted_at',
            ]);
        });
    }
};
