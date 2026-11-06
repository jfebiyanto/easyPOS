<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Support\ActivityLogger;
use App\Support\PriceListSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $query = Product::where('company_id', $companyId)->with(['group', 'brand', 'unit']);

        if ($search = $request->query('q')) {
            $query->where(fn (Builder $q) => $q->whereLike('name', "%{$search}%")
                ->orWhereLike('sku', "%{$search}%")
                ->orWhereLike('barcode', "%{$search}%"));
        }
        if ($groupId = $request->query('group_id')) {
            $query->where('product_group_id', $groupId);
        }
        if ($request->boolean('only_active')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->orderBy('sku')->get()]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeCompany($request, $product->company_id);

        return response()->json(['data' => $product->load(['group', 'brand', 'unit'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $product = Product::create($this->validated($request) + ['company_id' => $this->companyId($request)]);

        PriceListSync::syncBasePrice($product);

        ActivityLogger::log('product.created', "Created product (API): {$product->sku}", $product);

        return response()->json(['data' => $product->fresh(['group', 'brand', 'unit'])], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeCompany($request, $product->company_id);

        $oldBasePrice = $product->base_price;
        $product->update($this->validated($request, $product->id));

        if ($oldBasePrice != $product->base_price) {
            PriceListSync::syncBasePrice($product);
        }

        ActivityLogger::log('product.updated', "Updated product (API): {$product->sku}", $product, [
            'old_base_price' => $oldBasePrice, 'new_base_price' => $product->base_price,
        ]);

        return response()->json(['data' => $product->fresh(['group', 'brand', 'unit'])]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeCompany($request, $product->company_id);

        $label = $product->sku;
        $product->delete();

        ActivityLogger::log('product.deleted', "Deleted product (API): {$label}", $product);

        return response()->json(['message' => 'Product deleted.']);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $companyId = $this->companyId($request);

        return $request->validate([
            'product_group_id' => ['required', Rule::exists('product_groups', 'id')->where('company_id', $companyId)],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('company_id', $companyId)],
            'unit_id' => ['required', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'sku' => ['required', 'string', 'max:40', Rule::unique('products', 'sku')->where('company_id', $companyId)->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:60', Rule::unique('products', 'barcode')->where('company_id', $companyId)->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
