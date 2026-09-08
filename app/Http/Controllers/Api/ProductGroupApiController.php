<?php

namespace App\Http\Controllers\Api;

use App\Models\ProductGroup;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductGroupApiController extends ApiController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ProductGroup::withCount('products')->get()]);
    }

    public function show(ProductGroup $group): JsonResponse
    {
        return response()->json(['data' => $group]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperuser($request);

        $group = ProductGroup::create(
            $this->validated($request) + ['store_id' => active_store_id()]
        );

        ActivityLogger::log('product_group.created', "Membuat product group (API): {$group->code}", $group);

        return response()->json(['data' => $group], 201);
    }

    public function update(Request $request, ProductGroup $group): JsonResponse
    {
        $this->ensureSuperuser($request);

        $group->update($this->validated($request, $group->id));

        ActivityLogger::log('product_group.updated', "Mengubah product group (API): {$group->code}", $group);

        return response()->json(['data' => $group->fresh()]);
    }

    public function destroy(Request $request, ProductGroup $group): JsonResponse
    {
        $this->ensureSuperuser($request);

        if ($group->products()->exists()) {
            return response()->json(['message' => 'Group masih memiliki produk.'], 422);
        }

        $group->delete();

        ActivityLogger::log('product_group.deleted', "Menghapus product group (API): {$group->code}", $group);

        return response()->json(['message' => 'Product group dihapus.']);
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