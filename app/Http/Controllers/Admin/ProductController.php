<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $query = Product::with(['group', 'brand']);

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
        ]);
    }

    public function store(Request $request)
    {
        $product = Product::create($this->validated($request) + ['store_id' => active_store_id()]);

        $this->syncDefaultPriceList($product);

        ActivityLogger::log('product.created', "Membuat produk: {$product->sku} · {$product->name} (harga Rp ".number_format($product->base_price, 0, ',', '.').')', $product);

        return redirect()->route('admin.products.index')->with('success', 'Produk berhasil dibuat.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.products.form', [
            'product' => $product,
            'groups' => ProductGroup::orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $oldBasePrice = $product->base_price;

        $product->update($this->validated($request, $product->id));

        if ($oldBasePrice != $product->base_price) {
            $this->syncDefaultPriceList($product);
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
        $label = $product->sku.' · '.$product->name;
        $product->delete();

        ActivityLogger::log('product.deleted', "Menghapus produk: {$label}", $product);

        return redirect()->route('admin.products.index')->with('success', 'Produk berhasil dihapus.');
    }

    /**
     * Sinkronkan harga jual produk ke price list aktif (default) milik store.
     * Item dibuat/diperbarui dengan min_qty = 1.
     */
    private function syncDefaultPriceList(Product $product): void
    {
        $priceList = PriceList::where('store_id', $product->store_id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('effective_from')
            ->first();

        if (! $priceList) {
            return;
        }

        PriceListItem::updateOrCreate(
            ['price_list_id' => $priceList->id, 'product_id' => $product->id, 'min_qty' => 1],
            ['price' => $product->base_price]
        );
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'product_group_id' => ['required', 'exists:product_groups,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'sku' => ['required', 'string', 'max:40', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'unit' => ['required', 'string', 'max:20'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}