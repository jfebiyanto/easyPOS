<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuid7, HasFactory, Notifiable;

    protected $fillable = [
        'store_id', 'name', 'username', 'email', 'phone', 'type', 'is_superuser', 'is_active', 'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    public const TYPES = ['admin', 'cashier'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_superuser' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function isAdmin(): bool
    {
        return $this->type === 'admin';
    }

    /**
     * Superuser: admin dengan hak penuh (kelola store, product group, price list).
     * Admin non-superuser hanya read-only pada modul-modul tersebut.
     */
    public function isSuperuser(): bool
    {
        return $this->isAdmin() && $this->is_superuser;
    }
}
