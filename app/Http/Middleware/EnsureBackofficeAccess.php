<?php

namespace App\Http\Middleware;

use App\Support\BackofficeAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menegakkan matriks akses backoffice (lihat BackofficeAccess) untuk Store Manager.
 */
class EnsureBackofficeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeName = $request->route()?->getName();

        if ($user->isStoreManager() && ! $user->store_id) {
            abort(403, 'Akun Anda belum ditugaskan ke gerai. Hubungi Owner.');
        }

        if (! BackofficeAccess::allows($user, $routeName, $request->method())) {
            abort(403, 'Anda tidak memiliki akses ke halaman atau aksi ini.');
        }

        View::share('readOnly', BackofficeAccess::isReadOnly($user, $routeName));

        return $next($request);
    }
}
