<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Request;

trait AuthorizesSuperuser
{
    /**
     * Aksi tingkat perusahaan (store, user, company, numbering, activity log)
     * hanya untuk Owner. Store Manager tidak diizinkan.
     */
    protected function ensureSuperuser(Request $request): void
    {
        if (! $request->user()->isOwner()) {
            abort(403, 'Aksi ini hanya dapat dilakukan oleh Owner.');
        }
    }
}
