<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasUuid7;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'store_id', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'description', 'properties',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
