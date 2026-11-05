<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosSessionController extends Controller
{
    /**
     * Ambil alih sesi POS kasir ini: sesi aktif di perangkat lain diakhiri, dan perangkat ini menjadi sesi aktif.
     */
    public function takeover(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = PosSession::activeFor($user);

        if ($current && $current->session_hash === PosSession::hashFor($request)) {
            return response()->json(['data' => ['taken_over' => false]]);
        }

        $session = PosSession::startFor($request);

        if ($current === null) {
            $session->log('pos.session_started', "Sesi POS {$user->name} dimulai dari {$session->browser}, IP {$session->ip_address}");

            return response()->json(['data' => ['taken_over' => false]]);
        }

        $current->end(PosSession::ENDED_TAKEN_OVER);
        $session->log(
            'pos.session_taken_over',
            "Sesi POS {$user->name} diambil alih oleh {$session->browser}, IP {$session->ip_address} dari {$current->browser}, IP {$current->ip_address}",
            ['replaced_session' => ['id' => $current->id] + $current->summary()],
        );

        return response()->json(['data' => ['taken_over' => true]]);
    }

    /**
     * Detak sesi aktif; melewati middleware sesi sehingga perangkat yang sudah tergeser menerima 409.
     */
    public function heartbeat(): JsonResponse
    {
        return response()->json(['data' => ['active' => true]]);
    }
}
