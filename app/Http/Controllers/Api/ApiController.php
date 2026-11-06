<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Base API controller dengan validasi owner dan scope company.
 * abort(403) otomatis menjadi JSON untuk request API.
 */
abstract class ApiController extends Controller
{
    protected function ensureSuperuser(Request $request): void
    {
        if (! $request->user('sanctum')->isOwner()) {
            abort(403, 'This action can only be performed by an Owner.');
        }
    }

    /**
     * Company context untuk user token.
     */
    protected function companyId(Request $request): ?string
    {
        return $request->user('sanctum')->company_id;
    }

    /**
     * Pastikan model berada dalam company yang sama dengan user.
     */
    protected function authorizeCompany(Request $request, ?string $modelCompanyId): void
    {
        abort_unless($modelCompanyId !== null && $modelCompanyId === $this->companyId($request), 404);
    }
}
