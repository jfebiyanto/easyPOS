<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasUuid7;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const SAP_PENDING = 'pending';

    public const SAP_POSTED = 'posted';

    public const SAP_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'store_id', 'number', 'date', 'reason', 'status', 'created_by', 'posted_at',
        'sap_post_status', 'sap_post_error', 'sap_receipt_obj_type', 'sap_receipt_doc_num', 'sap_receipt_doc_entry',
        'sap_issue_obj_type', 'sap_issue_doc_num', 'sap_issue_doc_entry', 'sap_posted_at',
    ];

    protected $hidden = ['sap_receipt_doc_entry', 'sap_issue_doc_entry'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'posted_at' => 'datetime',
            'sap_posted_at' => 'datetime',
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
