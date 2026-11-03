<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Tax Group (Output Tax), padanan OVTG di SAP B1. Sumber `sap` = hasil impor (hanya-baca),
     * `manual` = dibuat user bila tidak terintegrasi SAP.
     */
    public function up(): void
    {
        Schema::create('tax_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->decimal('rate', 7, 4)->default(0);
            $table->date('effective_from')->nullable();
            $table->string('source', 10)->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::table('company_details', function (Blueprint $table) {
            $table->foreignUuid('tax_group_id')->nullable()->constrained('tax_groups')->nullOnDelete();
        });

        // Snapshot kode & tarif pajak per baris, mengikuti perilaku SAP.
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->string('tax_group_code', 30)->nullable();
            $table->decimal('tax_rate', 7, 4)->nullable();
        });

        // Perusahaan yang sudah memakai tarif manual: jadikan Tax Group manual dan pilih.
        foreach (DB::table('company_details')->get(['id', 'ppn_enabled', 'ppn_rate']) as $company) {
            if (! $company->ppn_enabled) {
                continue;
            }

            $rate = (float) $company->ppn_rate;
            $code = 'PPN-'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
            $id = (string) Str::uuid7();

            DB::table('tax_groups')->insert([
                'id' => $id,
                'company_id' => $company->id,
                'code' => $code,
                'name' => 'PPN '.rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',').'%',
                'rate' => $rate,
                'source' => 'manual',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('company_details')->where('id', $company->id)->update(['tax_group_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn(['tax_group_code', 'tax_rate']);
        });

        Schema::table('company_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_group_id');
        });

        Schema::dropIfExists('tax_groups');
    }
};
