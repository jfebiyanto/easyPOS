<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePosStoreIsActive
{
    /**
     * Kasir dan Store Manager hanya dapat memakai POS di satu gerai aktif yang ditugaskan Owner.
     * Penjualan offline yang membawa gerai asalnya tetap diterima, karena transaksinya sudah terjadi.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->isOwner() || $this->isOfflineSaleWithOriginStore($request)) {
            return $next($request);
        }

        abort_unless($user->store_id, 422, 'Your account has not been assigned to a store. Contact the Owner.');
        abort_unless($user->store?->is_active, 422, 'Your store is inactive. Contact the Owner.');

        return $next($request);
    }

    private function isOfflineSaleWithOriginStore(Request $request): bool
    {
        return $request->routeIs('pos.sales.store')
            && $request->input('origin') === 'offline'
            && $request->filled('store_id');
    }
}
