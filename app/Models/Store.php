<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasUuid7;

    protected $fillable = ['code', 'name', 'address', 'pic_name', 'pic_phone', 'ppn_enabled', 'ppn_rate', 'currency'];

    protected function casts(): array
    {
        return [
            'ppn_enabled' => 'boolean',
            'ppn_rate' => 'decimal:2',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function productGroups()
    {
        return $this->hasMany(ProductGroup::class);
    }

    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    public function priceLists()
    {
        return $this->hasMany(PriceList::class);
    }

    public function paymentTypes()
    {
        return $this->belongsToMany(PaymentType::class);
    }

    /**
     * Record mata uang gerai di modul Mata Uang (tabel currencies).
     * Kolom `currency` menyimpan kode mata uang (IDR, USD, ...), sehingga
     * akses `$store->currency` tetap berupa kode; gunakan relasi ini untuk
     * mengambil data lengkap dari modul Mata Uang.
     *
     * Nama method sengaja bukan `currency()` agar tidak bentrok dengan
     * kolom foreign key `currency` (menyebabkan rekursi saat model belum
     * tersimpan).
     */
    public function currencyRecord()
    {
        return $this->belongsTo(Currency::class, 'currency', 'code');
    }

    /**
     * The price list used to auto-fill product prices (default + active).
     */
    public function defaultPriceList()
    {
        return $this->hasOne(PriceList::class)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('effective_from');
    }
}
