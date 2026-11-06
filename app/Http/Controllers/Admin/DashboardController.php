<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'store' => Store::find(active_store_id()),
        ]);
    }

    /**
     * Data section "Penjualan Hari Ini" untuk store aktif (dipanggil ulang tiap 5 menit).
     */
    public function today(): JsonResponse
    {
        $storeId = active_store_id();
        abort_unless($storeId, 422, 'Belum ada store aktif.');

        return response()->json(['data' => DashboardService::today($storeId)]);
    }

    /**
     * Ringkasan hari ini: Owner melihat semua gerai, Store Manager hanya gerainya sendiri.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        $stores = $user->isOwner()
            ? Store::where('company_id', current_company_id())->orderBy('code')->get()
            : Store::where('company_id', current_company_id())->whereKey($user->store_id)->get();

        return response()->json(['data' => ['scope' => $user->isOwner() ? 'all' : 'store'] + DashboardService::overview($stores)]);
    }
}
