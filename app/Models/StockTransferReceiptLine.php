<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Database\Factories\StockTransferReceiptLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransferReceiptLine extends Model
{
    /** @use HasFactory<StockTransferReceiptLineFactory> */
    use HasFactory, HasUuid7;

    protected $fillable = ['receipt_id', 'shipment_line_id', 'qty'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3'];
    }

    public function receipt()
    {
        return $this->belongsTo(StockTransferReceipt::class, 'receipt_id');
    }

    public function shipmentLine()
    {
        return $this->belongsTo(StockTransferShipmentLine::class, 'shipment_line_id');
    }
}
