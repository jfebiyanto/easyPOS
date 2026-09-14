<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\NumberingConfig;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NumberingApiController extends ApiController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => NumberingConfig::allTypes()->values()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureSuperuser($request);

        $this->updateConfigs($request);

        return response()->json(['message' => 'Konfigurasi penomoran diperbarui.', 'data' => NumberingConfig::allTypes()->values()]);
    }

    /**
     * Generate nomor transaksi berikutnya (dipakai POS).
     */
    public function next(string $transactionType): JsonResponse
    {
        abort_unless(array_key_exists($transactionType, NumberingConfig::TYPES), 404, 'Tipe transaksi tidak dikenal.');

        return response()->json([
            'transaction_type' => $transactionType,
            'number' => NumberingConfig::nextNumber($transactionType),
        ]);
    }

    private function updateConfigs(Request $request): void
    {
        foreach ($request->input('configs', []) as $type => $input) {
            if (! array_key_exists($type, NumberingConfig::TYPES)) {
                continue;
            }

            $config = NumberingConfig::query()
                ->where('company_id', $this->companyId($request))
                ->where('transaction_type', $type)
                ->first();
            if (! $config) {
                continue;
            }

            $config->update([
                'prefix' => trim($input['prefix'] ?? ''),
                'padding' => max(1, min(12, (int) ($input['padding'] ?? 4))),
                'separator' => substr($input['separator'] ?? '/', 0, 1),
                'reset_period' => in_array($input['reset_period'] ?? '', array_keys(NumberingConfig::RESET_PERIODS)) ? $input['reset_period'] : 'monthly',
                'last_number' => max(0, (int) ($input['next_number'] ?? 1) - 1),
            ]);

            ActivityLogger::log('numbering.updated', "Mengubah penomoran (API) {$config->transaction_type}", $config);
        }
    }
}