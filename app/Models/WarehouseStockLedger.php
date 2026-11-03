<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * Buku besar stok per warehouse — append-only.
 */
class WarehouseStockLedger extends Model
{
    use HasUuid7;

    public const UPDATED_AT = null;

    public const TYPE_OPENING = 'opening';

    public const TYPE_SALE = 'sale';

    public const TYPE_SALE_RETURN = 'sale_return';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    protected $fillable = [
        'company_id', 'warehouse_id', 'product_id', 'type', 'qty', 'balance_after', 'unit_cost',
        'ref_type', 'ref_id', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
