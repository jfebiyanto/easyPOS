<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class SaleDraft extends Model
{
    use HasUuid7;

    protected $fillable = [
        'company_id', 'store_id', 'client_uuid', 'cashier_id', 'device_label', 'payload', 'status',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
