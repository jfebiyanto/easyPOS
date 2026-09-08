<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * Konfigurasi perusahaan (single-row) milik HO.
 */
class CompanyDetail extends Model
{
    use HasUuid7;

    public const RESET_PERIODS = [
        'daily' => 'Reset Harian (contoh: INV/20260908/0001)',
        'monthly' => 'Reset Bulanan (contoh: INV/202609/0001)',
        'yearly' => 'Reset Tahunan (contoh: INV/2026/0001)',
        'never' => 'Tidak pernah reset (contoh: INV/0001, 0002, ... berlanjut)',
    ];

    protected $fillable = [
        'name', 'address', 'npwp_number', 'phone', 'email',
        'ppn_enabled', 'ppn_rate',
        'sap_enabled', 'sap_verify_ssl', 'sap_endpoint_url', 'sap_db_name', 'sap_username', 'sap_password',
    ];

    protected $hidden = ['sap_password'];

    protected function casts(): array
    {
        return [
            'ppn_enabled' => 'boolean',
            'ppn_rate' => 'decimal:2',
            'sap_enabled' => 'boolean',
            'sap_verify_ssl' => 'boolean',
            'sap_password' => 'encrypted',
        ];
    }

    /**
     * Apakah kredensial SAP B1 sudah lengkap.
     */
    public function hasSapConfig(): bool
    {
        return filled($this->sap_endpoint_url) && filled($this->sap_db_name)
            && filled($this->sap_username) && filled($this->sap_password);
    }

    /**
     * Toggle integrasi ON dan kredensial lengkap.
     * Saat false, aplikasi dianggap berjalan standalone (tanpa SAP).
     */
    public function sapIntegrationActive(): bool
    {
        return $this->sap_enabled && $this->hasSapConfig();
    }

    /**
     * Ambil satu-satunya baris konfigurasi, dibuat otomatis jika belum ada.
     */
    public static function current(): self
    {
        return static::query()->first() ?? static::create(['name' => 'My Company']);
    }
}