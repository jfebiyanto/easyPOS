<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\ProductGroup;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductGroupController extends Controller
{
    use AuthorizesCompany;

    public function index(): View
    {
        return view('admin.products.groups.index', [
            'groups' => apply_sort(
                ProductGroup::withCount('products'),
                request('sort'), request('dir'),
                ['code', 'name', 'products_count', 'is_active', 'created_at'],
                'created_at'
            )->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.groups.form', ['group' => new ProductGroup]);
    }

    public function store(Request $request)
    {
        $group = ProductGroup::create($this->validated($request) + ['company_id' => current_company_id()]);

        ActivityLogger::log('product_group.created', "Membuat product group: {$group->code} · {$group->name}", $group);

        return redirect()->route('admin.groups.index')->with('success', 'Product group created successfully.');
    }

    public function edit(ProductGroup $group): View
    {
        $this->ensureCompany($group->company_id);

        return view('admin.products.groups.form', ['group' => $group]);
    }

    public function update(Request $request, ProductGroup $group)
    {
        $this->ensureCompany($group->company_id);

        $group->update($this->validated($request, $group->id));

        ActivityLogger::log('product_group.updated', "Mengubah product group: {$group->code} · {$group->name}", $group);

        return redirect()->route('admin.groups.index')->with('success', 'Product group updated successfully.');
    }

    public function destroy(ProductGroup $group)
    {
        $this->ensureCompany($group->company_id);

        if ($group->products()->exists()) {
            return back()->with('error', 'Group still has products and cannot be deleted.');
        }

        $code = $group->code.' · '.$group->name;
        $group->delete();

        ActivityLogger::log('product_group.deleted', "Menghapus product group: {$code}", $group);

        return redirect()->route('admin.groups.index')->with('success', 'Product group deleted successfully.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('product_groups', 'code')->where('company_id', current_company_id())->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
