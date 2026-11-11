<?php

namespace Tests\Concerns;

use App\Models\CashierShift;
use App\Models\Store;
use App\Models\StoreClosure;
use App\Models\User;
use App\Support\ShiftService;

/**
 * Pembantu test: kasir sudah memulai shift (dan toko sudah dibuka), sehingga POS menerima transaksi online.
 */
trait StartsShift
{
    protected function startShiftFor(User $cashier, ?Store $store = null, float $float = 0): CashierShift
    {
        $store ??= Store::findOrFail($cashier->store_id);
        $count = ['amount' => $float, 'denominations' => [], 'remark' => 'test'];

        return StoreClosure::isOpen($store->id, ShiftService::postingDate($store))
            ? ShiftService::startShift($store, $cashier, $count)
            : ShiftService::openStore($store, $cashier, $count);
    }
}
