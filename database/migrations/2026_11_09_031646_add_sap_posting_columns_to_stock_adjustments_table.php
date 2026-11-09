<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status posting penyesuaian stok ke SAP B1: Goods Receipt (selisih positif, ObjType 59) dan
     * Goods Issue (selisih negatif, ObjType 60). ObjType + DocNum ditampilkan; DocEntry hanya kunci teknis.
     */
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->string('sap_post_status', 10)->nullable()->index();
            $table->text('sap_post_error')->nullable();
            $table->integer('sap_receipt_obj_type')->nullable();
            $table->string('sap_receipt_doc_num', 20)->nullable();
            $table->integer('sap_receipt_doc_entry')->nullable();
            $table->integer('sap_issue_obj_type')->nullable();
            $table->string('sap_issue_doc_num', 20)->nullable();
            $table->integer('sap_issue_doc_entry')->nullable();
            $table->timestamp('sap_posted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropIndex(['sap_post_status']);
            $table->dropColumn([
                'sap_post_status', 'sap_post_error',
                'sap_receipt_obj_type', 'sap_receipt_doc_num', 'sap_receipt_doc_entry',
                'sap_issue_obj_type', 'sap_issue_doc_num', 'sap_issue_doc_entry',
                'sap_posted_at',
            ]);
        });
    }
};
