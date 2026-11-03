<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperuser($request);

        $query = ActivityLog::query();

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q->whereLike('description', "%{$search}%")
                ->orWhereLike('actor_name', "%{$search}%"));
        }

        return response()->json([
            'data' => $query->latest('created_at')->limit((int) min($request->query('limit', 100), 500))->get(),
        ]);
    }
}
