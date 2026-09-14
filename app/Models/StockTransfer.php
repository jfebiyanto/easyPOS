<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use HasUuid7;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'number', 'from_store_id', 'to_store_id', 'status', 'note',
        'created_by', 'shipped_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function fromStore()
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore()
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function lines()
    {
        return $this->hasMany(StockTransferLine::class);
    }

    public function isShipped(): bool
    {
        return in_array($this->status, [self::STATUS_SHIPPED, self::STATUS_RECEIVED], true);
    }
}
