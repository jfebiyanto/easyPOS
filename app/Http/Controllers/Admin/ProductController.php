<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Unit;
use App\Support\ActivityLogger;
use App\Support\PriceListSync;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    use AuthorizesCompany;
    public function index(Request $request): View
    {
        $query = Product::with(['group', 'brand', 'unit']);

        if ($search = $request->string('q')->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%"));
        }

        if ($groupId = $request->query('group')) {
            $query->where('product_group_id', $groupId);
        }

        $query = apply_sort(
            $query,
            $request->query('sort'), $request->query('dir'),
            ['sku', 'name', 'base_price', 'is_active', 'created_at'],
            'created_at'
        );

        return view('admin.products.products.index', [
            'products' => $query->paginate(10)->withQueryString(),
            'groups' => ProductGroup::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.products.form', [
            'product' => new Product(),
            'groups' => ProductGroup::orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'units' => Unit::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $product = Product::create($this->validated($request) + ['company_id' => current_company_id()]);

        PriceListSync::syncBasePrice($product);

        ActivityLogger::log('product.created', "Membuat produk: {$product->sku} · {$product->name} (harga Rp ".number_format($product->base_price, 0, ',', '.').')', $product);

        return redirect()->route('admin.products.index')->with('success', 'Produk berhasil dibuat.');
    }

    public function edit(Product $product): View
    {
        $this->ensureCompany($product->company_id);

        return view('admin.products.products.form', [
            'product' => $product,
            'groups' => ProductGroup::orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'units' => Unit::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $this->ensureCompany($product->company_id);

        $oldBasePrice = $product->base_price;

        $product->update($this->validated($request, $product->id));

        if ($oldBasePrice != $product->base_price) {
            PriceListSync::syncBasePrice($product);
        }

        ActivityLogger::log('product.updated', "Mengubah produk: {$product->sku} · {$product->name}", $product, [
            'base_price_changed' => $oldBasePrice != $product->base_price,
            'old_base_price' => $oldBasePrice,
            'new_base_price' => $product->base_price,
        ]);

        return redirect()->route('admin.products.index')->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroy(Product $product)
    {
        $this->ensureCompany($product->company_id);

        $label = $product->sku.' · '.$product->name;
        $product->delete();

        ActivityLogger::log('product.deleted', "Menghapus produk: {$label}", $product);

        return redirect()->route('admin.products.index')->with('success', 'Produk berhasil dihapus.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'product_group_id' => ['required', Rule::exists('product_groups', 'id')->where('company_id', current_company_id())],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('company_id', current_company_id())],
            'unit_id' => ['required', Rule::exists('units', 'id')->where('company_id', current_company_id())],
            'sku' => ['required', 'string', 'max:40', Rule::unique('products', 'sku')->where('company_id', current_company_id())->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:60', Rule::unique('products', 'barcode')->where('company_id', current_company_id())->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
