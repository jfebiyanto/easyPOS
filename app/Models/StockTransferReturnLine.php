<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Database\Factories\StockTransferReturnLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransferReturnLine extends Model
{
    /** @use HasFactory<StockTransferReturnLineFactory> */
    use HasFactory, HasUuid7;

    protected $fillable = ['return_id', 'shipment_line_id', 'qty'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3'];
    }

    public function stockTransferReturn()
    {
        return $this->belongsTo(StockTransferReturn::class, 'return_id');
    }

    public function shipmentLine()
    {
        return $this->belongsTo(StockTransferShipmentLine::class, 'shipment_line_id');
    }
}
