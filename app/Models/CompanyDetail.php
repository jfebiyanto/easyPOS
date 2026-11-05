<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * Konfigurasi perusahaan (single-row) — identitas, pajak, kebijakan global.
 */
class CompanyDetail extends Model
{
    use HasUuid7;

    protected $fillable = [
        'name', 'address', 'logo_path', 'npwp_number', 'phone', 'email',
        'ppn_enabled', 'ppn_rate', 'ppn_inclusive', 'tax_group_id',
        'base_currency_code', 'timezone', 'allow_negative_stock',
        'return_window_days', 'return_require_receipt', 'sku_config', 'payment_providers',
        'sap_enabled', 'sap_verify_ssl', 'sap_endpoint_url', 'sap_db_name', 'sap_username', 'sap_password',
        'sap_sync_interval_minutes', 'sap_auto_sync', 'sap_last_synced_at', 'sap_initial_imported_at',
        'sap_stock_synced_at', 'sap_stock_sync_error', 'sap_sync_watermarks', 'sap_stock_reconciled_at',
        'promotion_hierarchy',
    ];

    protected $hidden = ['sap_password', 'payment_providers'];

    /**
     * Urutan sumber diskon (hierarki promosi): sumber paling atas yang menghasilkan diskon pada baris menang.
     * Kunci yang belum tersimpan atau tidak dikenal dirapikan ke bawah/dibuang; bawaan = urutan Promotion::HIERARCHY_SOURCES.
     *
     * @return list<string>
     */
    public function promotionHierarchy(): array
    {
        $known = array_keys(Promotion::HIERARCHY_SOURCES);
        $saved = array_values(array_unique(array_filter(
            (array) $this->promotion_hierarchy,
            fn ($key) => in_array($key, $known, true),
        )));

        return array_merge($saved, array_values(array_diff($known, $saved)));
    }

    protected function casts(): array
    {
        return [
            'ppn_enabled' => 'boolean',
            'ppn_inclusive' => 'boolean',
            'ppn_rate' => 'decimal:2',
            'allow_negative_stock' => 'boolean',
            'return_require_receipt' => 'boolean',
            'sku_config' => 'array',
            'promotion_hierarchy' => 'array',
            'payment_providers' => 'encrypted:array',
            'sap_enabled' => 'boolean',
            'sap_verify_ssl' => 'boolean',
            'sap_password' => 'encrypted',
            'sap_auto_sync' => 'boolean',
            'sap_last_synced_at' => 'datetime',
            'sap_initial_imported_at' => 'datetime',
            'sap_stock_synced_at' => 'datetime',
            'sap_sync_watermarks' => 'array',
            'sap_stock_reconciled_at' => 'datetime',
        ];
    }

    public function taxGroup()
    {
        return $this->belongsTo(TaxGroup::class);
    }

    public function hasSapConfig(): bool
    {
        return filled($this->sap_endpoint_url) && filled($this->sap_db_name)
            && filled($this->sap_username) && filled($this->sap_password);
    }

    public function sapIntegrationActive(): bool
    {
        return $this->sap_enabled && $this->hasSapConfig();
    }

    /**
     * Setelah impor awal, koneksi SAP terkunci: database tidak boleh diganti dan
     * perubahan lain wajib dikonfirmasi password Owner.
     */
    public function sapConnectionLocked(): bool
    {
        return $this->sap_initial_imported_at !== null;
    }

    /**
     * Ambil satu-satunya baris konfigurasi, dibuat otomatis jika belum ada.
     */
    public static function current(): self
    {
        return static::query()->first() ?? static::create(['name' => 'My Company']);
    }

    public static function currentId(): ?string
    {
        return static::query()->orderBy('created_at')->value('id');
    }
}
