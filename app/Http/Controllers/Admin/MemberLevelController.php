<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyConfig;
use App\Models\MemberLevel;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MemberLevelController extends Controller
{
    use AuthorizesSuperuser;

    public function index(): View
    {
        return view('admin.member-levels.index', [
            'levels' => MemberLevel::orderBy('min_points')->get(),
            'config' => LoyaltyConfig::current(),
        ]);
    }

    public function create(): View
    {
        $this->ensureSuperuser(request());

        return view('admin.member-levels.form', ['level' => new MemberLevel]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperuser($request);

        $level = MemberLevel::create($this->validated($request) + [
            'company_id' => current_company_id(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        ActivityLogger::log('member_level.created', "Menambah level member: {$level->name}", $level);

        return redirect()->route('admin.member-levels.index')->with('success', 'Level member berhasil dibuat.');
    }

    public function edit(MemberLevel $memberLevel): View
    {
        $this->ensureSuperuser(request());

        return view('admin.member-levels.form', ['level' => $memberLevel]);
    }

    public function update(Request $request, MemberLevel $memberLevel)
    {
        $this->ensureSuperuser($request);

        $memberLevel->update($this->validated($request, $memberLevel->id) + [
            'is_active' => $request->boolean('is_active'),
        ]);

        ActivityLogger::log('member_level.updated', "Mengubah level member: {$memberLevel->name}", $memberLevel);

        return redirect()->route('admin.member-levels.index')->with('success', 'Level member berhasil diperbarui.');
    }

    public function destroy(MemberLevel $memberLevel)
    {
        $this->ensureSuperuser(request());

        if ($memberLevel->customers()->exists()) {
            return back()->with('error', 'Level masih dipakai customer, tidak dapat dihapus.');
        }

        $memberLevel->delete();

        ActivityLogger::log('member_level.deleted', "Menghapus level member: {$memberLevel->name}", $memberLevel);

        return redirect()->route('admin.member-levels.index')->with('success', 'Level member berhasil dihapus.');
    }

    public function updateConfig(Request $request)
    {
        $this->ensureSuperuser($request);

        $data = $request->validate([
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'rounding' => ['required', Rule::in(['none', 'down', 'up', 'nearest'])],
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $config = LoyaltyConfig::current();
        $config->update([
            'min_spend' => $data['min_spend'] ?? 0,
            'rounding' => $data['rounding'],
            'expiry_days' => $data['expiry_days'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        ActivityLogger::log('loyalty.config_updated', 'Mengubah konfigurasi loyalitas', $config);

        return redirect()->route('admin.member-levels.index')->with('success', 'Konfigurasi loyalitas disimpan.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('member_levels', 'name')->where('company_id', current_company_id())->ignore($id)],
            'min_points' => ['required', 'numeric', 'min:0'],
            'earn_amount_per_point' => ['required', 'numeric', 'min:1'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
