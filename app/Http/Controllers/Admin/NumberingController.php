<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\NumberingConfig;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NumberingController extends Controller
{
    use AuthorizesSuperuser;

    public function index(): View
    {
        $this->ensureSuperuser(request());

        return view('admin.numberings.index', [
            'configs' => NumberingConfig::allTypes(),
        ]);
    }

    public function update(Request $request)
    {
        $this->ensureSuperuser($request);

        foreach ($request->input('configs', []) as $type => $input) {
            if (! array_key_exists($type, NumberingConfig::TYPES)) {
                continue;
            }

            $config = NumberingConfig::query()->where('transaction_type', $type)->first();
            if (! $config) {
                continue;
            }

            $old = $config->only(['prefix', 'padding', 'separator', 'reset_period', 'last_number']);

            $config->update([
                'prefix' => trim($input['prefix'] ?? ''),
                'padding' => max(1, min(12, (int) ($input['padding'] ?? 4))),
                'separator' => substr($input['separator'] ?? '/', 0, 1),
                'reset_period' => in_array($input['reset_period'] ?? '', array_keys(NumberingConfig::RESET_PERIODS)) ? $input['reset_period'] : 'monthly',
                'last_number' => max(0, (int) ($input['next_number'] ?? 1) - 1),
            ]);

            if ($old !== $config->only(['prefix', 'padding', 'separator', 'reset_period', 'last_number'])) {
                ActivityLogger::log(
                    'numbering.updated',
                    "Mengubah penomoran {$config->transaction_type} (prefix: {$config->prefix}, reset: {$config->reset_period}, no. berikutnya: ".($config->last_number + 1).')',
                    $config,
                    ['old' => $old, 'new' => $config->only(['prefix', 'padding', 'separator', 'reset_period', 'last_number'])]
                );
            }
        }

        return redirect()->route('admin.numberings.index')->with('success', 'Konfigurasi penomoran transaksi berhasil disimpan.');
    }
}