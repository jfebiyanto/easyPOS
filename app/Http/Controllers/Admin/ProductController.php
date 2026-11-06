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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ProductController extends Controller
{
    use AuthorizesCompany;

    public function index(Request $request): View
    {
        $query = Product::with(['group', 'brand', 'unit']);

        if ($search = $request->string('q')->toString()) {
            $query->where(fn ($q) => $q->whereLike('name', "%{$search}%")
                ->orWhereLike('sku', "%{$search}%")
                ->orWhereLike('barcode', "%{$search}%"));
        }

        if ($groupId = $request->query('group')) {
            $query->where('product_group_id', $groupId);
        }

        $query = apply_sort(
            $query,
            $request->query('sort'), $request->query('dir'),
            ['sku', 'name', 'base_price', 'is_active', 'created_at'],
            'sku',
            'asc'
        );

        return view('admin.products.products.index', [
            'products' => $query->paginate(10)->withQueryString(),
            'groups' => ProductGroup::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.products.form', [
            'product' => new Product,
            'groups' => ProductGroup::orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'units' => Unit::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $image = $data['image'] ?? null;
        unset($data['image'], $data['remove_image']);

        $newImagePath = $image?->store('products', 'public');

        try {
            $product = DB::transaction(function () use ($data, $newImagePath) {
                $product = Product::create($data + ['company_id' => current_company_id(), 'image_path' => $newImagePath]);

                PriceListSync::syncBasePrice($product);

                return $product;
            });
        } catch (Throwable $e) {
            $this->deleteImageFile($newImagePath);

            throw $e;
        }

        ActivityLogger::log('product.created', "Created product: {$product->sku} · {$product->name} (price Rp ".number_format($product->base_price, 0, ',', '.').')', $product);

        return redirect()->route('admin.products.index')->with('success', 'Product created successfully.');
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

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->ensureCompany($product->company_id);

        $oldBasePrice = $product->base_price;

        $oldImagePath = $product->image_path;

        $data = $this->validated($request, $product->id);
        $image = $data['image'] ?? null;
        $removeImage = (bool) ($data['remove_image'] ?? false);
        unset($data['image'], $data['remove_image']);

        $newImagePath = $image?->store('products', 'public');

        if ($newImagePath) {
            $data['image_path'] = $newImagePath;
        } elseif ($removeImage) {
            $data['image_path'] = null;
        }

        try {
            DB::transaction(function () use ($product, $data, $oldBasePrice) {
                $product->update($data);

                if ($oldBasePrice != $product->base_price) {
                    PriceListSync::syncBasePrice($product);
                }
            });
        } catch (Throwable $e) {
            $this->deleteImageFile($newImagePath);

            throw $e;
        }

        if ($oldImagePath !== $product->image_path) {
            $this->deleteImageFile($oldImagePath);
        }

        ActivityLogger::log('product.updated', "Updated product: {$product->sku} · {$product->name}", $product, [
            'base_price_changed' => $oldBasePrice != $product->base_price,
            'old_base_price' => $oldBasePrice,
            'new_base_price' => $product->base_price,
        ]);

        return redirect()->route('admin.products.index')->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->ensureCompany($product->company_id);

        $label = $product->sku.' · '.$product->name;
        $product->delete();
        $this->deleteImageFile($product->image_path);

        ActivityLogger::log('product.deleted', "Deleted product: {$label}", $product);

        return redirect()->route('admin.products.index')->with('success', 'Product deleted successfully.');
    }

    private function deleteImageFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
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
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
