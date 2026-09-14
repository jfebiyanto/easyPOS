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

    public const ROLE_OWNER = 'owner';
    public const ROLE_STORE_MANAGER = 'store_manager';
    public const ROLE_CASHIER = 'cashier';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_STORE_MANAGER,
        self::ROLE_CASHIER,
    ];

    protected $fillable = [
        'company_id', 'store_id', 'name', 'username', 'email', 'phone', 'role', 'is_active', 'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function company()
    {
        return $this->belongsTo(CompanyDetail::class, 'company_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isStoreManager(): bool
    {
        return $this->role === self::ROLE_STORE_MANAGER;
    }

    public function isCashier(): bool
    {
        return $this->role === self::ROLE_CASHIER;
    }

    /**
     * Bisa masuk ke backoffice (admin): Owner dan Store Manager.
     * Kasir hanya untuk halaman POS.
     */
    public function canAccessBackoffice(): bool
    {
        return $this->isOwner() || $this->isStoreManager();
    }

    /**
     * Alias lama agar kompatibel: admin backoffice = owner/manager.
     */
    public function isAdmin(): bool
    {
        return $this->canAccessBackoffice();
    }

    /**
     * Owner memiliki hak tertinggi (setara superuser lama).
     */
    public function isSuperuser(): bool
    {
        return $this->isOwner();
    }
}
