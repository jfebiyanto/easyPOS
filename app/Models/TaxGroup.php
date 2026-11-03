<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Tax Group (Output Tax). Data dari SAP (`source = sap`) hanya-baca.
 */
class TaxGroup extends MasterModel
{
    use HasFactory;

    public const SOURCE_SAP = 'sap';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = ['company_id', 'code', 'name', 'rate', 'effective_from', 'source', 'is_active'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function isFromSap(): bool
    {
        return $this->source === self::SOURCE_SAP;
    }

    /**
     * Label untuk dropdown, mis. "PPNK-11 · PPN Keluaran 11% (11%)".
     */
    public function label(): string
    {
        return $this->code.' · '.$this->name.' ('.self::formatRate((float) $this->rate).'%)';
    }

    public static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 4, ',', '.'), '0'), ',');
    }
}
