<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BrandController extends Controller
{
    use AuthorizesCompany;
    public function index(): View
    {
        return view('admin.products.brands.index', [
            'brands' => apply_sort(
                Brand::withCount('products'),
                request('sort'), request('dir'),
                ['code', 'name', 'products_count', 'is_active', 'created_at'],
                'created_at'
            )->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.brands.form', ['brand' => new Brand()]);
    }

    public function store(Request $request)
    {
        $brand = Brand::create($this->validated($request) + ['company_id' => current_company_id()]);

        ActivityLogger::log('brand.created', "Membuat brand: {$brand->code} · {$brand->name}", $brand);

        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil dibuat.');
    }

    public function edit(Brand $brand): View
    {
        $this->ensureCompany($brand->company_id);

        return view('admin.products.brands.form', ['brand' => $brand]);
    }

    public function update(Request $request, Brand $brand)
    {
        $this->ensureCompany($brand->company_id);

        $brand->update($this->validated($request, $brand->id));

        ActivityLogger::log('brand.updated', "Mengubah brand: {$brand->code} · {$brand->name}", $brand);

        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil diperbarui.');
    }

    public function destroy(Brand $brand)
    {
        $this->ensureCompany($brand->company_id);

        if ($brand->products()->exists()) {
            return back()->with('error', 'Brand masih dipakai oleh produk, tidak dapat dihapus.');
        }

        $label = $brand->code.' · '.$brand->name;
        $brand->delete();

        ActivityLogger::log('brand.deleted', "Menghapus brand: {$label}", $brand);

        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil dihapus.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('brands', 'code')->where('company_id', current_company_id())->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
