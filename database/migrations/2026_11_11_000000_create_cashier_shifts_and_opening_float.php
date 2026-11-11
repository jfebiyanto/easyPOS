<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    /**
     * Open Store dan shift kasir: pembuka toko tercatat di store_closures, modal standar per toko,
     * dan shift per kasir beserta modal awal (laci uang sendiri) dan rincian denominasinya.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('opening_float', 15, 2)->default(0)->after('currency');
        });

        Schema::table('store_closures', function (Blueprint $table) {
            $table->foreignUuid('opened_by')->nullable()->after('opened_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('business_date');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 20)->nullable();
            $table->boolean('opened_store')->default(false);
            $table->decimal('opening_float', 15, 2)->default(0);
            $table->decimal('opening_float_expected', 15, 2)->default(0);
            $table->decimal('opening_float_variance', 15, 2)->default(0);
            $table->text('opening_float_remark')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'business_date']);
            $table->index(['user_id', 'ended_at']);
        });

        Schema::create('cashier_shift_denominations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cashier_shift_id')->constrained()->cascadeOnDelete();
            $table->decimal('denomination_value', 15, 2);
            $table->unsignedInteger('count')->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->timestamps();
        });

        $this->backfillOpenedStores();
    }

    /**
     * Toko dan tanggal yang sudah punya transaksi dianggap sudah dibuka sejak transaksi pertamanya,
     * supaya EOD historis tidak terblokir. Shift lama tidak dibuat ulang.
     */
    private function backfillOpenedStores(): void
    {
        $firstSales = DB::table('sales')
            ->select('company_id', 'store_id', 'business_date', DB::raw('MIN(created_at) as first_at'))
            ->groupBy('company_id', 'store_id', 'business_date')
            ->get();

        foreach ($firstSales as $row) {
            $date = substr((string) $row->business_date, 0, 10);
            $existing = DB::table('store_closures')->where('store_id', $row->store_id)->whereDate('business_date', $date)->first();

            if ($existing) {
                if ($existing->opened_at === null) {
                    DB::table('store_closures')->where('id', $existing->id)->update(['opened_at' => $row->first_at]);
                }

                continue;
            }

            DB::table('store_closures')->insert([
                'id' => strtolower(Uuid::uuid7()->toString()),
                'company_id' => $row->company_id,
                'store_id' => $row->store_id,
                'business_date' => $date,
                'opened_at' => $row->first_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_denominations');
        Schema::dropIfExists('cashier_shifts');

        Schema::table('store_closures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opened_by');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('opening_float');
        });
    }
};
