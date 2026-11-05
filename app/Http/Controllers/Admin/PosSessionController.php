<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\PosSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Audit sesi POS kasir: siapa, dari browser dan IP mana, kapan, dan bagaimana sesinya berakhir.
 */
class PosSessionController extends Controller
{
    use AuthorizesSuperuser;

    public function index(Request $request): View
    {
        $this->ensureSuperuser($request);

        $status = $request->query('status');

        return view('admin.pos-sessions.index', [
            'sessions' => PosSession::query()
                ->with(['user:id,name,username', 'store:id,code'])
                ->when($status === 'active', fn ($q) => $q->whereNull('ended_at')->where('last_seen_at', '>=', now()->subSeconds(PosSession::STALE_AFTER_SECONDS)))
                ->when($status === 'ended', fn ($q) => $q->where(fn ($x) => $x->whereNotNull('ended_at')->orWhere('last_seen_at', '<', now()->subSeconds(PosSession::STALE_AFTER_SECONDS))))
                ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($x) => $x->whereLike('ip_address', "%{$s}%")->orWhereLike('browser', "%{$s}%")
                    ->orWhereHas('user', fn ($u) => $u->whereLike('name', "%{$s}%")->orWhereLike('username', "%{$s}%"))))
                ->latest('started_at')
                ->paginate(20)
                ->withQueryString(),
            'status' => $status,
        ]);
    }
}
