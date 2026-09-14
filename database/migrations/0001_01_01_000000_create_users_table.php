<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Perusahaan (single-row per instalasi). Menyimpan identitas,
        // kebijakan pajak, mata uang dasar, timezone, dan kebijakan global.
        Schema::create('company_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('npwp_number', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('ppn_enabled')->default(false);
            $table->decimal('ppn_rate', 5, 2)->default(11.00);
            $table->boolean('ppn_inclusive')->default(false);
            $table->string('base_currency_code', 10)->default('IDR');
            $table->string('timezone', 40)->default('Asia/Jakarta');
            $table->boolean('allow_negative_stock')->default(false);
            $table->unsignedSmallInteger('return_window_days')->nullable()->default(30);
            $table->boolean('return_require_receipt')->default(true);
            $table->json('sku_config')->nullable();
            $table->text('payment_providers')->nullable(); // JSON terenkripsi
            // Integrasi SAP (legacy, di luar roadmap MVP).
            $table->boolean('sap_enabled')->default(false);
            $table->boolean('sap_verify_ssl')->default(true);
            $table->string('sap_endpoint_url', 255)->nullable();
            $table->string('sap_db_name', 60)->nullable();
            $table->string('sap_username', 60)->nullable();
            $table->text('sap_password')->nullable();
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('pic_name')->nullable();
            $table->string('pic_phone', 30)->nullable();
            $table->boolean('ppn_enabled')->default(false);
            $table->decimal('ppn_rate', 5, 2)->default(11.00);
            $table->string('currency', 10)->default('IDR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('company_details')->cascadeOnDelete();
            $table->foreignUuid('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('username', 60)->unique();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->enum('role', ['owner', 'store_manager', 'cashier'])->default('cashier');
            $table->boolean('is_active')->default(true);
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('username')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('company_details');
    }
};
