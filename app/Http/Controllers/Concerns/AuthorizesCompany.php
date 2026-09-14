<?php

namespace App\Http\Controllers\Concerns;

trait AuthorizesCompany
{
    /**
     * Pastikan model yang di-bind lewat route berada di company aktif.
     * Mencegah IDOR lintas tenant saat platform menjadi multi-company.
     */
    protected function ensureCompany(?string $modelCompanyId): void
    {
        abort_unless($modelCompanyId !== null && $modelCompanyId === current_company_id(), 404);
    }
}
