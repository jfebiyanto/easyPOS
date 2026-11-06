<?php

namespace App\Models;

use App\Models\Concerns\PostsToSap;
use App\Traits\HasUuid7;
use Database\Factories\StockTransferShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Shipment: gudang pengirim ke gudang in-transit pengirim. Diposting ke SAP B1 sebagai Stock Transfer.
 */
class StockTransferShipment extends Model
{
    /** @use HasFactory<StockTransferShipmentFactory> */
    use HasFactory, HasUuid7, PostsToSap;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'company_id', 'number', 'from_store_id', 'to_store_id', 'from_warehouse_id', 'transit_warehouse_id',
        'to_warehouse_id', 'status', 'note', 'posted_at', 'created_by',
        'sap_post_status', 'sap_post_error', 'sap_doc_num', 'sap_doc_entry', 'sap_posted_at',
    ];

    protected $hidden = ['sap_doc_entry'];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'sap_posted_at' => 'datetime',
        ];
    }

    public function lines()
    {
        return $this->hasMany(StockTransferShipmentLine::class, 'shipment_id');
    }

    public function receipts()
    {
        return $this->hasMany(StockTransferReceipt::class, 'shipment_id');
    }

    public function returns()
    {
        return $this->hasMany(StockTransferReturn::class, 'shipment_id');
    }

    public function fromStore()
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore()
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function transitWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'transit_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /**
     * Status turunan untuk daftar: Draft, In Transit, Partially Received, Completed (dihitung dari baris).
     */
    public function displayStatus(): string
    {
        if ($this->status !== self::STATUS_POSTED) {
            return 'Draft';
        }

        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();
        $shipped = round((float) $lines->sum('qty'), 3);
        $received = round((float) $lines->sum('qty_received'), 3);

        $returned = round((float) $lines->sum('qty_returned'), 3);

        if ($received <= 0 && $returned <= 0) {
            return 'In Transit';
        }

        return round($received + $returned, 3) >= $shipped ? 'Completed' : 'Partially Received';
    }
}
