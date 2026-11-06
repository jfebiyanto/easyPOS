<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    use AuthorizesCompany;
    use AuthorizesSuperuser;

    public function index(Request $request): View
    {
        return view('admin.warehouses.index', [
            'warehouses' => apply_sort(
                Warehouse::with('store:id,warehouse_id,code,name'),
                $request->query('sort'), $request->query('dir'),
                ['code', 'name', 'profit_center', 'is_retail', 'is_active', 'created_at'],
                'code', 'asc'
            )->paginate(10),
        ]);
    }

    public function create(Request $request): View
    {
        $this->ensureSuperuser($request);

        return view('admin.warehouses.form', ['warehouse' => new Warehouse]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperuser($request);

        $warehouse = Warehouse::create($this->validated($request) + ['company_id' => current_company_id()]);

        ActivityLogger::log('warehouse.created', "Membuat warehouse: {$warehouse->code} · {$warehouse->name}", $warehouse);

        return redirect()->route('admin.warehouses.index')->with('success', 'Warehouse berhasil dibuat.');
    }

    public function edit(Request $request, Warehouse $warehouse): View
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($warehouse->company_id);

        return view('admin.warehouses.form', ['warehouse' => $warehouse]);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($warehouse->company_id);

        $warehouse->update($this->validated($request, $warehouse->id));

        ActivityLogger::log('warehouse.updated', "Mengubah warehouse: {$warehouse->code} · {$warehouse->name}", $warehouse);

        return redirect()->route('admin.warehouses.index')->with('success', 'Warehouse berhasil diperbarui.');
    }

    public function destroy(Request $request, Warehouse $warehouse)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($warehouse->company_id);

        if ($warehouse->store()->exists()) {
            return back()->with('error', 'Warehouse masih dipakai oleh gerai, tidak dapat dihapus.');
        }

        $label = $warehouse->code.' · '.$warehouse->name;
        $warehouse->delete();

        ActivityLogger::log('warehouse.deleted', "Menghapus warehouse: {$label}", $warehouse);

        return redirect()->route('admin.warehouses.index')->with('success', 'Warehouse berhasil dihapus.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('warehouses', 'code')->where('company_id', current_company_id())->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'profit_center' => ['nullable', 'string', 'max:50'],
        ]);

        $data['is_retail'] = $request->boolean('is_retail');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
