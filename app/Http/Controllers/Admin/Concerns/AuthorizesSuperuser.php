<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Request;

trait AuthorizesSuperuser
{
    /**
     * Superuser: admin ber-flag is_superuser.
     * Admin non-superuser hanya boleh read pada modul terkait.
     */
    protected function ensureSuperuser(Request $request): void
    {
        if (! $request->user()->isSuperuser()) {
            abort(403, 'Aksi ini hanya dapat dilakukan oleh Superuser. Hak akses Anda hanya read-only.');
        }
    }
}