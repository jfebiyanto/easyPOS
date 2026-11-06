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
            abort(403, 'Your account has not been assigned to a store. Contact the Owner.');
        }

        if (! BackofficeAccess::allows($user, $routeName, $request->method())) {
            abort(403, 'You do not have access to this page or action.');
        }

        View::share('readOnly', BackofficeAccess::isReadOnly($user, $routeName));

        return $next($request);
    }
}
