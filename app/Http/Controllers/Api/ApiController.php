<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Base API controller dengan validasi superuser.
 * abort(403) otomatis menjadi JSON untuk request API.
 */
abstract class ApiController extends Controller
{
    protected function ensureSuperuser(Request $request): void
    {
        if (! $request->user('sanctum')->isSuperuser()) {
            abort(403, 'Aksi ini hanya dapat dilakukan oleh Superuser.');
        }
    }
}