<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasUuid7;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'company_id', 'store_id', 'number', 'date', 'reason', 'status', 'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function lines()
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }
}
