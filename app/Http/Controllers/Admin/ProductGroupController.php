<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\ProductGroup;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductGroupController extends Controller
{
    use AuthorizesSuperuser;

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
        $this->ensureSuperuser(request());

        return view('admin.products.groups.form', ['group' => new ProductGroup()]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperuser($request);
        $group = ProductGroup::create($this->validated($request) + ['store_id' => active_store_id()]);

        ActivityLogger::log('product_group.created', "Membuat product group: {$group->code} · {$group->name}", $group);

        return redirect()->route('admin.groups.index')->with('success', 'Product group berhasil dibuat.');
    }

    public function edit(ProductGroup $group): View
    {
        $this->ensureSuperuser(request());

        return view('admin.products.groups.form', ['group' => $group]);
    }

    public function update(Request $request, ProductGroup $group)
    {
        $this->ensureSuperuser($request);
        $group->update($this->validated($request, $group->id));

        ActivityLogger::log('product_group.updated', "Mengubah product group: {$group->code} · {$group->name}", $group);

        return redirect()->route('admin.groups.index')->with('success', 'Product group berhasil diperbarui.');
    }

    public function destroy(ProductGroup $group)
    {
        $this->ensureSuperuser(request());
        if ($group->products()->exists()) {
            return back()->with('error', 'Group masih memiliki produk, tidak dapat dihapus.');
        }

        $code = $group->code.' · '.$group->name;
        $group->delete();

        ActivityLogger::log('product_group.deleted', "Menghapus product group: {$code}", $group);

        return redirect()->route('admin.groups.index')->with('success', 'Product group berhasil dihapus.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('product_groups', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}