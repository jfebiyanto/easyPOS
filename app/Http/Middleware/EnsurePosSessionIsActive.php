<?php

namespace App\Http\Middleware;

use App\Models\PosSession;
use App\Support\ActivityLogger;
use App\Support\UserAgentLabel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePosSessionIsActive
{
    /**
     * Satu sesi POS aktif per kasir. Sesi pertama langsung aktif; perangkat lain ditolak (409) sampai
     * mengambil alih atau sesi lama kedaluwarsa (tanpa detak). Browser, IP, dan kejadiannya dicatat untuk audit.
     * Penjualan offline yang baru disinkronkan tetap diterima karena transaksinya sudah terjadi.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isOfflineSaleSync($request)) {
            return $next($request);
        }

        $user = $request->user();
        $hash = PosSession::hashFor($request);
        $current = PosSession::activeFor($user);

        if ($current === null) {
            $this->start($request);
        } elseif ($current->session_hash === $hash) {
            $this->touch($request, $current);
        } elseif ($current->isStale()) {
            $current->end(PosSession::ENDED_EXPIRED);
            $current->log('pos.session_expired', "Sesi POS {$user->name} kedaluwarsa karena tidak ada detak sejak {$current->last_seen_at->timezone(config('app.timezone'))->format('d/m/Y H:i:s')} ({$current->browser}, {$current->ip_address})");
            $this->start($request);
        } else {
            return $this->reject($request, $current);
        }

        return $next($request);
    }

    private function start(Request $request): void
    {
        $session = PosSession::startFor($request);
        $session->log('pos.session_started', "Sesi POS {$request->user()->name} dimulai dari {$session->browser}, IP {$session->ip_address}");
    }

    /**
     * Perbarui detak (paling sering tiap 10 detik) dan catat bila IP berubah di tengah sesi.
     */
    private function touch(Request $request, PosSession $session): void
    {
        $ipChanged = $request->ip() !== $session->ip_address;

        if (! $ipChanged && $session->last_seen_at->gt(now()->subSeconds(10))) {
            return;
        }

        $previousIp = $session->ip_address;
        $session->forceFill(['last_seen_at' => now(), 'ip_address' => $request->ip()])->save();

        if ($ipChanged) {
            $session->log('pos.session_ip_changed', "IP sesi POS {$request->user()->name} berubah dari {$previousIp} menjadi {$session->ip_address}", ['previous_ip' => $previousIp]);
        }
    }

    private function reject(Request $request, PosSession $active): Response
    {
        // Hanya percobaan awal (membuka POS) yang dicatat; detak dan permintaan dari perangkat tergeser terlalu sering.
        if ($request->routeIs('pos.bootstrap')) {
            ActivityLogger::log(
                'pos.session_blocked',
                "Sesi POS {$request->user()->name} ditolak dari ".UserAgentLabel::describe($request->userAgent()).", IP {$request->ip()}: sudah aktif di {$active->browser}, IP {$active->ip_address}",
                null,
                [
                    'attempt' => ['browser' => UserAgentLabel::describe($request->userAgent()), 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()],
                    'active_session' => $active->summary(),
                ],
            );
        }

        return response()->json([
            'message' => 'Akun ini sedang memakai POS di perangkat atau browser lain.',
            'code' => 'pos_session_active',
            'active_session' => $active->summary(),
        ], 409);
    }

    private function isOfflineSaleSync(Request $request): bool
    {
        return $request->routeIs('pos.sales.store') && $request->input('origin') === 'offline';
    }
}
