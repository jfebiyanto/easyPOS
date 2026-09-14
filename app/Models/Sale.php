<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasUuid7;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'company_id', 'store_id', 'number', 'client_uuid', 'origin', 'sync_status', 'business_date',
        'type', 'customer_id', 'points_earned', 'cashier_id', 'status', 'stock_review_status', 'review_reason', 'resolved_by', 'resolved_at',
        'subtotal', 'discount_total', 'tax_total', 'shipping_cost', 'total', 'paid_total', 'change_total',
        'currency_code', 'sold_at', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'sold_at' => 'datetime',
            'voided_at' => 'datetime',
            'resolved_at' => 'datetime',
            'points_earned' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'change_total' => 'decimal:2',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines()
    {
        return $this->hasMany(SaleLine::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    public function delivery()
    {
        return $this->hasOne(Delivery::class);
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }
}
