<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Web (session) diprioritaskan; fallback ke token Sanctum untuk API.
        $user = $request->user() ?? $request->user('sanctum');

        if (! $user || ! $user->is_active || ! $user->isAdmin()) {
            abort(403, 'Hanya user Owner/Store Manager yang dapat mengakses modul backoffice.');
        }

        return $next($request);
    }
}