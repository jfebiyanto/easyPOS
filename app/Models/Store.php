<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasUuid7;

    protected $fillable = [
        'company_id', 'code', 'name', 'address', 'pic_name', 'pic_phone',
        'ppn_enabled', 'ppn_rate', 'currency', 'opening_float', 'is_active', 'purchase_price_list_id', 'warehouse_id', 'default_customer_id',
    ];

    /**
     * Modal awal standar (SOP) yang diberikan ke tiap kasir. Titik tunggal agar nanti bisa diganti
     * pengaturan per terminal POS tanpa mengubah alur shift.
     */
    public function openingFloatStandard(): float
    {
        return (float) $this->opening_float;
    }

    public function defaultCustomer()
    {
        return $this->belongsTo(Customer::class, 'default_customer_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected function casts(): array
    {
        return [
            'ppn_enabled' => 'boolean',
            'ppn_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(CompanyDetail::class, 'company_id');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Pricelist yang di-assign ke store ini (company-level atau store-level).
     */
    public function priceLists()
    {
        return $this->belongsToMany(PriceList::class, 'store_price_lists')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function paymentTypes()
    {
        return $this->belongsToMany(PaymentType::class);
    }

    public function currencyRecord()
    {
        return $this->belongsTo(Currency::class, 'currency', 'code');
    }

    /**
     * Store yang menjadi asal/tujuan Shipment atau penerima Receipt tidak boleh dihapus (riwayat transfer stok).
     */
    public function hasStockTransferDocuments(): bool
    {
        return StockTransferShipment::where('from_store_id', $this->id)->orWhere('to_store_id', $this->id)->exists()
            || StockTransferReceipt::where('store_id', $this->id)->exists();
    }
}
