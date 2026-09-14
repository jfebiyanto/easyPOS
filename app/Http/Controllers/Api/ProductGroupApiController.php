<?php

namespace App\Http\Controllers\Api;

use App\Models\ProductGroup;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductGroupApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ProductGroup::where('company_id', $this->companyId($request))
                ->withCount('products')
                ->get(),
        ]);
    }

    public function show(Request $request, ProductGroup $group): JsonResponse
    {
        $this->authorizeCompany($request, $group->company_id);

        return response()->json(['data' => $group]);
    }

    public function store(Request $request): JsonResponse
    {
        $group = ProductGroup::create($this->validated($request) + ['company_id' => $this->companyId($request)]);

        ActivityLogger::log('product_group.created', "Membuat product group (API): {$group->code}", $group);

        return response()->json(['data' => $group], 201);
    }

    public function update(Request $request, ProductGroup $group): JsonResponse
    {
        $this->authorizeCompany($request, $group->company_id);

        $group->update($this->validated($request, $group->id));

        ActivityLogger::log('product_group.updated', "Mengubah product group (API): {$group->code}", $group);

        return response()->json(['data' => $group->fresh()]);
    }

    public function destroy(Request $request, ProductGroup $group): JsonResponse
    {
        $this->authorizeCompany($request, $group->company_id);

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
            'code' => ['required', 'string', 'max:30', Rule::unique('product_groups', 'code')->where('company_id', $this->companyId($request))->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
