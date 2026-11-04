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

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_PURCHASE_RETURN = 'purchase_return';

    public const TYPE_GOODS_RECEIPT = 'goods_receipt';

    public const TYPE_GOODS_ISSUE = 'goods_issue';

    public const TYPE_PRODUCTION = 'production';

    public const TYPE_SAP_OTHER = 'sap_other';

    /**
     * Jenis mutasi easyPOS untuk TransType SAP B1 (objek dokumen). Transfer ditentukan dari arah qty.
     *
     * @var array<int, string>
     */
    public const SAP_TRANS_TYPES = [
        13 => self::TYPE_SALE,               // A/R Invoice
        14 => self::TYPE_SALE_RETURN,        // A/R Credit Memo
        15 => self::TYPE_SALE,               // Delivery
        16 => self::TYPE_SALE_RETURN,        // Return
        18 => self::TYPE_PURCHASE,           // A/P Invoice
        19 => self::TYPE_PURCHASE_RETURN,    // A/P Credit Memo
        20 => self::TYPE_PURCHASE,           // Goods Receipt PO
        21 => self::TYPE_PURCHASE_RETURN,    // Goods Return
        59 => self::TYPE_GOODS_RECEIPT,      // Goods Receipt
        60 => self::TYPE_GOODS_ISSUE,        // Goods Issue
        67 => self::TYPE_TRANSFER_IN,        // Inventory Transfer (arah dari qty)
        202 => self::TYPE_PRODUCTION,        // Production Order
        10000071 => self::TYPE_ADJUSTMENT,   // Inventory Posting
        310000001 => self::TYPE_OPENING,     // Inventory Opening Balance
    ];

    protected $fillable = [
        'company_id', 'warehouse_id', 'product_id', 'type', 'qty', 'balance_after', 'unit_cost',
        'ref_type', 'ref_id', 'note', 'created_by',
        'sap_trans_seq', 'sap_trans_type', 'sap_doc_entry', 'sap_doc_num', 'sap_doc_line', 'doc_date',
    ];

    /**
     * Cost item hanya dilihat di SAP B1.
     */
    protected $hidden = ['unit_cost'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'doc_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Nama objek dokumen SAP B1 (ObjType) untuk ditampilkan bersama DocNum.
     *
     * @var array<int, string>
     */
    public const SAP_OBJECT_NAMES = [
        13 => 'A/R Invoice',
        14 => 'A/R Credit Memo',
        15 => 'Delivery',
        16 => 'Return',
        18 => 'A/P Invoice',
        19 => 'A/P Credit Memo',
        20 => 'Goods Receipt PO',
        21 => 'Goods Return',
        59 => 'Goods Receipt',
        60 => 'Goods Issue',
        67 => 'Inventory Transfer',
        202 => 'Production Order',
        10000071 => 'Inventory Posting',
        310000001 => 'Inventory Opening Balance',
    ];

    /**
     * Referensi dokumen SAP yang ditampilkan: ObjType + DocNum (DocEntry tidak ditampilkan).
     */
    public function sapDocumentLabel(): ?string
    {
        if ($this->sap_doc_num === null) {
            return null;
        }

        $object = self::SAP_OBJECT_NAMES[$this->sap_trans_type] ?? 'ObjType '.$this->sap_trans_type;

        return "{$object} {$this->sap_doc_num}";
    }

    public static function typeForSap(int $transType, float $qty): string
    {
        if ($transType === 67) {
            return $qty < 0 ? self::TYPE_TRANSFER_OUT : self::TYPE_TRANSFER_IN;
        }

        return self::SAP_TRANS_TYPES[$transType] ?? self::TYPE_SAP_OTHER;
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
