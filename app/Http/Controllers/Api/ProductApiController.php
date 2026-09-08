<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['group', 'brand']);

        if ($search = $request->query('q')) {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%"));
        }
        if ($groupId = $request->query('group_id')) {
            $query->where('product_group_id', $groupId);
        }
        if ($request->boolean('only_active')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->load(['group', 'brand'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $product = Product::create($this->validated($request) + ['store_id' => active_store_id()]);

        $this->syncDefaultPriceList($product);

        ActivityLogger::log('product.created', "Membuat produk (API): {$product->sku}", $product);

        return response()->json(['data' => $product->fresh(['group', 'brand'])], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $oldBasePrice = $product->base_price;

        $product->update($this->validated($request, $product->id));

        if ($oldBasePrice != $product->base_price) {
            $this->syncDefaultPriceList($product);
        }

        ActivityLogger::log('product.updated', "Mengubah produk (API): {$product->sku}", $product, [
            'old_base_price' => $oldBasePrice, 'new_base_price' => $product->base_price,
        ]);

        return response()->json(['data' => $product->fresh(['group', 'brand'])]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $label = $product->sku;
        $product->delete();

        ActivityLogger::log('product.deleted', "Menghapus produk (API): {$label}", $product);

        return response()->json(['message' => 'Produk dihapus.']);
    }

    private function syncDefaultPriceList(Product $product): void
    {
        $priceList = \App\Models\PriceList::where('store_id', $product->store_id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('effective_from')
            ->first();

        if (! $priceList) {
            return;
        }

        \App\Models\PriceListItem::updateOrCreate(
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