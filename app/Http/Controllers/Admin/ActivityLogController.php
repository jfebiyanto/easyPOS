<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(): View
    {
        $user = request()->user();

        return view('admin.logs.index', [
            'logs' => apply_sort(
                ActivityLog::query(),
                request('sort'), request('dir'),
                ['created_at', 'actor_name', 'action'],
                'created_at'
            )->when(! $user->isOwner(), fn ($q) => $q->where('store_id', $user->store_id))
                ->when(request('q'), fn ($q, $s) => $q->where(fn ($x) => $x->whereLike('description', "%{$s}%")->orWhereLike('actor_name', "%{$s}%")))
                ->when(request('action'), fn ($q, $a) => $q->where('action', $a))
                ->paginate(20)
                ->withQueryString(),
        ]);
    }
}
