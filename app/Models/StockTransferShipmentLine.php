<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Database\Factories\StockTransferShipmentLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransferShipmentLine extends Model
{
    /** @use HasFactory<StockTransferShipmentLineFactory> */
    use HasFactory, HasUuid7;

    protected $fillable = ['shipment_id', 'product_id', 'qty', 'qty_received'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'qty_received' => 'decimal:3'];
    }

    public function shipment()
    {
        return $this->belongsTo(StockTransferShipment::class, 'shipment_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Sisa yang belum diterima: dikirim dikurangi sudah diterima.
     */
    public function remaining(): float
    {
        return round((float) $this->qty - (float) $this->qty_received, 3);
    }
}
