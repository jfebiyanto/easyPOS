<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    use AuthorizesSuperuser;

    public function index(): View
    {
        $this->ensureSuperuser(request());

        return view('admin.logs.index', [
            'logs' => apply_sort(
                ActivityLog::query(),
                request('sort'), request('dir'),
                ['created_at', 'actor_name', 'action'],
                'created_at'
            )->when(request('q'), fn ($q, $s) => $q->where(fn ($x) => $x->whereLike('description', "%{$s}%")->orWhereLike('actor_name', "%{$s}%")))
                ->when(request('action'), fn ($q, $a) => $q->where('action', $a))
                ->paginate(20)
                ->withQueryString(),
        ]);
    }
}
