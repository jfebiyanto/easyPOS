<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use HasUuid7;

    public const SAP_PENDING = 'pending';

    /** A/R Credit Memo ada di SAP, pengembalian dana tunai belum. */
    public const SAP_CREDITED = 'credited';

    public const SAP_POSTED = 'posted';

    public const SAP_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'store_id', 'number', 'original_sale_id', 'reason', 'total',
        'refund_method', 'receipt_verified', 'created_by',
        'sap_post_status', 'sap_post_error', 'sap_credit_memo_obj_type', 'sap_credit_memo_doc_num', 'sap_credit_memo_doc_entry',
        'sap_refund_obj_type', 'sap_refund_doc_num', 'sap_refund_doc_entry', 'sap_posted_at',
    ];

    protected $hidden = ['sap_credit_memo_doc_entry', 'sap_refund_doc_entry'];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'receipt_verified' => 'boolean',
            'sap_posted_at' => 'datetime',
        ];
    }

    public function lines()
    {
        return $this->hasMany(SalesReturnLine::class);
    }

    public function originalSale()
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Pengembalian dana tunai (satu-satunya yang dibuatkan Outgoing Payment di SAP).
     */
    public function isCashRefund(): bool
    {
        return strtolower(trim((string) $this->refund_method)) === 'cash';
    }
}
