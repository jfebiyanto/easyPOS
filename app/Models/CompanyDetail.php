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
        'ppn_enabled', 'ppn_rate', 'ppn_inclusive',
        'base_currency_code', 'timezone', 'allow_negative_stock',
        'return_window_days', 'return_require_receipt', 'sku_config', 'payment_providers',
        'sap_enabled', 'sap_verify_ssl', 'sap_endpoint_url', 'sap_db_name', 'sap_username', 'sap_password',
    ];

    protected $hidden = ['sap_password', 'payment_providers'];

    protected function casts(): array
    {
        return [
            'ppn_enabled' => 'boolean',
            'ppn_inclusive' => 'boolean',
            'ppn_rate' => 'decimal:2',
            'allow_negative_stock' => 'boolean',
            'return_require_receipt' => 'boolean',
            'sku_config' => 'array',
            'payment_providers' => 'encrypted:array',
            'sap_enabled' => 'boolean',
            'sap_verify_ssl' => 'boolean',
            'sap_password' => 'encrypted',
        ];
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
