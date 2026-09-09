<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Mata uang default — menjadi sumber dropdown "Mata Uang" di form gerai.
        Currency::firstOrCreate(['code' => 'IDR'], ['symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]);
        Currency::firstOrCreate(['code' => 'USD'], ['symbol' => '$', 'decimal_places' => 2, 'is_active' => true]);

        $store = Store::create([
            'code' => 'STR001',
            'name' => 'easyPOS Main Store',
            'address' => 'Jl. Merdeka No. 1, Jakarta',
            'pic_name' => 'Store Manager',
            'pic_phone' => '081200000000',
            'ppn_enabled' => true,
            'ppn_rate' => 11,
            'currency' => 'IDR',
        ]);

        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'store_id' => $store->id,
                'name' => 'Administrator',
                'email' => 'admin@easypos.test',
                'type' => 'admin',
                'is_superuser' => true,
                'is_active' => true,
                'password' => 'admin12345',
            ]
        );

        User::create([
            'store_id' => $store->id,
            'name' => 'Kasir Contoh',
            'username' => 'kasir',
            'type' => 'cashier',
            'is_active' => true,
            'password' => 'kasir12345',
        ]);

        $group = ProductGroup::create(['store_id' => $store->id, 'code' => 'BEV', 'name' => 'Minuman']);
        $brand = Brand::create(['store_id' => $store->id, 'code' => 'AQR', 'name' => 'Aqua']);

        Product::create([
            'store_id' => $store->id,
            'product_group_id' => $group->id,
            'brand_id' => $brand->id,
            'sku' => 'SKU-0001',
            'name' => 'Aqua Botol 600ml',
            'unit' => 'PCS',
            'base_price' => 4000,
            'cost_price' => 3000,
        ]);
    }
}
