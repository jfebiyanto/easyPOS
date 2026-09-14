<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\CompanyDetail;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\MemberLevel;
use App\Models\NumberingConfig;
use App\Models\PaymentType;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Support\PriceListSync;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $company = CompanyDetail::firstOrCreate(
            ['name' => 'easyPOS Demo'],
            [
                'address' => 'Jl. Merdeka No. 1, Jakarta',
                'npwp_number' => '01.234.567.8-901.000',
                'phone' => '021-1234567',
                'email' => 'hello@easypos.test',
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
            [
                'name' => 'easyPOS Main Store',
                'address' => 'Jl. Merdeka No. 1, Jakarta',
                'pic_name' => 'Store Manager',
                'pic_phone' => '081200000000',
                'ppn_enabled' => true,
                'ppn_rate' => 11,
                'currency' => 'IDR',
                'is_active' => true,
            ]
        );

        $ownerPassword = env('SEED_ADMIN_PASSWORD') ?: Str::password(16);
        $managerPassword = env('SEED_MANAGER_PASSWORD') ?: Str::password(16);
        $cashierPassword = env('SEED_CASHIER_PASSWORD') ?: Str::password(16);

        $owner = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'company_id' => $company->id,
                'store_id' => $store->id,
                'name' => 'Owner',
                'email' => 'admin@easypos.test',
                'role' => User::ROLE_OWNER,
                'is_active' => true,
                'password' => $ownerPassword,
            ]
        );

        User::firstOrCreate(
            ['username' => 'manager'],
            [
                'company_id' => $company->id,
                'store_id' => $store->id,
                'name' => 'Store Manager',
                'role' => User::ROLE_STORE_MANAGER,
                'is_active' => true,
                'password' => $managerPassword,
            ]
        );

        $cashier = User::firstOrCreate(
            ['username' => 'kasir'],
            [
                'company_id' => $company->id,
                'store_id' => $store->id,
                'name' => 'Kasir Contoh',
                'role' => User::ROLE_CASHIER,
                'is_active' => true,
                'password' => $cashierPassword,
            ]
        );

        if (! env('SEED_ADMIN_PASSWORD')) {
            $this->command?->warn("Password owner ({$owner->username}): {$ownerPassword}");
        }
        if (! env('SEED_MANAGER_PASSWORD')) {
            $this->command?->warn("Password manager (manager): {$managerPassword}");
        }
        if (! env('SEED_CASHIER_PASSWORD')) {
            $this->command?->warn("Password kasir ({$cashier->username}): {$cashierPassword}");
        }

        // Mata uang + pecahan (referensi IDR untuk hitung kas EOD).
        $idr = Currency::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'IDR'],
            ['symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true]
        );
        foreach ([100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100] as $value) {
            $idr->denominations()->firstOrCreate(['value' => $value], ['is_active' => true]);
        }

        // Satuan
        $pcs = Unit::firstOrCreate(['company_id' => $company->id, 'code' => 'PCS'], ['name' => 'Pieces', 'is_active' => true]);
        Unit::firstOrCreate(['company_id' => $company->id, 'code' => 'BOX'], ['name' => 'Box', 'is_active' => true]);
        Unit::firstOrCreate(['company_id' => $company->id, 'code' => 'KG'], ['name' => 'Kilogram', 'is_active' => true]);

        // Katalog global per company
        $group = ProductGroup::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'BEV'],
            ['name' => 'Minuman', 'is_active' => true]
        );
        $brand = Brand::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'AQR'],
            ['name' => 'Aqua', 'is_active' => true]
        );

        $product = Product::firstOrCreate(
            ['company_id' => $company->id, 'sku' => 'SKU-0001'],
            [
                'product_group_id' => $group->id,
                'brand_id' => $brand->id,
                'unit_id' => $pcs->id,
                'name' => 'Aqua Botol 600ml',
                'base_price' => 4000,
                'cost_price' => 3000,
                'track_stock' => true,
                'is_active' => true,
            ]
        );

        // Pricelist default company + assign ke store.
        $priceList = PriceList::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'DEFAULT'],
            [
                'store_id' => null,
                'name' => 'Harga Default',
                'is_default' => true,
                'is_active' => true,
            ]
        );
        $store->priceLists()->syncWithoutDetaching([$priceList->id => ['is_default' => true]]);
        PriceListSync::syncBasePrice($product);

        // Tipe pembayaran
        foreach ([['CASH', 'Tunai', 1], ['QRIS', 'QRIS', 2], ['CARD', 'Kartu', 3]] as [$code, $desc, $order]) {
            $pt = PaymentType::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['description' => $desc, 'order_number' => $order, 'count_type' => 'summary', 'is_offline_allowed' => true, 'is_active' => true]
            );
            $pt->stores()->syncWithoutDetaching([$store->id]);
        }

        // Konfigurasi penomoran untuk semua tipe transaksi.
        NumberingConfig::allTypes($company->id);

        // Level member default + konfigurasi loyalitas.
        foreach ([['Silver', 0, 10000, 0], ['Gold', 100, 8000, 1], ['Platinum', 500, 6000, 2]] as [$name, $minPoints, $rate, $order]) {
            MemberLevel::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['min_points' => $minPoints, 'earn_amount_per_point' => $rate, 'order' => $order, 'is_active' => true]
            );
        }

        LoyaltyConfig::firstOrCreate(
            ['company_id' => $company->id],
            ['min_spend' => 0, 'rounding' => 'down', 'is_active' => true]
        );

        // Contoh member awal.
        Customer::firstOrCreate(
            ['company_id' => $company->id, 'phone' => '081200000001'],
            [
                'code' => 'CUST-00001',
                'name' => 'Pelanggan Contoh',
                'is_member' => true,
                'points_balance' => 0,
                'member_level_id' => MemberLevel::where('company_id', $company->id)->where('name', 'Silver')->value('id'),
                'joined_at' => now(),
                'is_active' => true,
            ]
        );
    }
}
