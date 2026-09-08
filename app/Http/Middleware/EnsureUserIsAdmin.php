<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Dukung guard session (web) dan token Sanctum (API).
        $user = $request->user('sanctum') ?? $request->user();

        if (! $user || ! $user->is_active || ! $user->isAdmin()) {
            abort(403, 'Hanya user tipe Admin yang dapat mengakses modul backoffice.');
        }

        return $next($request);
    }
}