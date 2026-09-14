<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Store;
use Illuminate\Http\Request;

trait ScopesToStore
{
    /**
     * Owner boleh memilih store; Store Manager terkunci ke store-nya.
     */
    protected function resolveStoreId(Request $request, ?string $requested = null): ?string
    {
        $user = $request->user();

        if ($user->isOwner()) {
            return $requested ?: ($user->store_id ?? Store::query()->orderBy('code')->value('id'));
        }

        return $user->store_id;
    }

    /**
     * Store yang boleh diakses user untuk operasi stok.
     *
     * @return array<int,string>
     */
    protected function allowedStoreIds(Request $request): array
    {
        $user = $request->user();

        if ($user->isOwner()) {
            return Store::where('company_id', current_company_id())->pluck('id')->all();
        }

        return array_filter([$user->store_id]);
    }
}
