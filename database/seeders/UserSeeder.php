<?php

namespace Database\Seeders;

use App\Models\CompanyDetail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Hanya akun login (Owner, Store Manager, Kasir) beserta company dan gerai yang dibutuhkan FK-nya.
 * Aman dijalankan ulang (firstOrCreate); tidak membuat data demo lain.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $company = CompanyDetail::firstOrCreate(
            ['name' => 'easyPOS Demo'],
            [
                'ppn_enabled' => true,
                'ppn_rate' => 11,
                'ppn_inclusive' => false,
                'base_currency_code' => 'IDR',
                'timezone' => 'Asia/Jakarta',
                'allow_negative_stock' => false,
                'return_window_days' => 30,
                'return_require_receipt' => true,
            ]
        );

        $store = Store::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'STR001'],
            ['name' => 'easyPOS Main Store', 'pic_name' => 'Store Manager', 'currency' => 'IDR', 'is_active' => true]
        );

        $accounts = [
            ['admin', 'Owner', User::ROLE_OWNER, 'SEED_ADMIN_PASSWORD'],
            ['manager', 'Store Manager', User::ROLE_STORE_MANAGER, 'SEED_MANAGER_PASSWORD'],
            ['kasir', 'Kasir Contoh', User::ROLE_CASHIER, 'SEED_CASHIER_PASSWORD'],
        ];

        foreach ($accounts as [$username, $name, $role, $envKey]) {
            $password = env($envKey) ?: Str::password(16);

            $user = User::firstOrCreate(
                ['username' => $username],
                [
                    'company_id' => $company->id,
                    'store_id' => $store->id,
                    'name' => $name,
                    'role' => $role,
                    'is_active' => true,
                    'password' => $password,
                ]
            );

            if ($user->wasRecentlyCreated && ! env($envKey)) {
                $this->command?->warn("Password {$username}: {$password}");
            }
        }
    }
}
