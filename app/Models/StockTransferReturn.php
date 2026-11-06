<?php

namespace App\Models;

use App\Models\Concerns\PostsToSap;
use App\Traits\HasUuid7;
use Database\Factories\StockTransferReturnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Return: gudang in-transit pengirim kembali ke gudang store pengirim. Diposting ke SAP B1 sebagai Stock Transfer.
 */
class StockTransferReturn extends Model
{
    /** @use HasFactory<StockTransferReturnFactory> */
    use HasFactory, HasUuid7, PostsToSap;

    protected $fillable = [
        'company_id', 'shipment_id', 'number', 'store_id', 'note', 'returned_at', 'created_by',
        'sap_post_status', 'sap_post_error', 'sap_doc_num', 'sap_doc_entry', 'sap_posted_at',
    ];

    protected $hidden = ['sap_doc_entry'];

    protected function casts(): array
    {
        return [
            'returned_at' => 'datetime',
            'sap_posted_at' => 'datetime',
        ];
    }

    public function shipment()
    {
        return $this->belongsTo(StockTransferShipment::class, 'shipment_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function lines()
    {
        return $this->hasMany(StockTransferReturnLine::class, 'return_id');
    }
}
