<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UnitController extends Controller
{
    use AuthorizesCompany;
    public function index(): View
    {
        return view('admin.units.index', [
            'units' => apply_sort(
                Unit::withCount('products'),
                request('sort'), request('dir'),
                ['code', 'name', 'products_count', 'is_active', 'created_at'],
                'created_at'
            )->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('admin.units.form', ['unit' => new Unit()]);
    }

    public function store(Request $request)
    {
        $unit = Unit::create($this->validated($request) + ['company_id' => current_company_id()]);

        ActivityLogger::log('unit.created', "Membuat satuan: {$unit->code} · {$unit->name}", $unit);

        return redirect()->route('admin.units.index')->with('success', 'Satuan berhasil dibuat.');
    }

    public function edit(Unit $unit): View
    {
        $this->ensureCompany($unit->company_id);

        return view('admin.units.form', ['unit' => $unit]);
    }

    public function update(Request $request, Unit $unit)
    {
        $this->ensureCompany($unit->company_id);

        $unit->update($this->validated($request, $unit->id));

        ActivityLogger::log('unit.updated', "Mengubah satuan: {$unit->code} · {$unit->name}", $unit);

        return redirect()->route('admin.units.index')->with('success', 'Satuan berhasil diperbarui.');
    }

    public function destroy(Unit $unit)
    {
        $this->ensureCompany($unit->company_id);

        if ($unit->products()->exists()) {
            return back()->with('error', 'Satuan masih dipakai produk, tidak dapat dihapus.');
        }

        $label = $unit->code.' · '.$unit->name;
        $unit->delete();

        ActivityLogger::log('unit.deleted', "Menghapus satuan: {$label}", $unit);

        return redirect()->route('admin.units.index')->with('success', 'Satuan berhasil dihapus.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('units', 'code')->where('company_id', current_company_id())->ignore($id)],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
