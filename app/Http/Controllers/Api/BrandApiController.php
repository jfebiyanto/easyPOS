<?php

namespace App\Http\Controllers\Api;

use App\Models\Brand;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Brand::where('company_id', $this->companyId($request))
                ->withCount('products')
                ->get(),
        ]);
    }

    public function show(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeCompany($request, $brand->company_id);

        return response()->json(['data' => $brand]);
    }

    public function store(Request $request): JsonResponse
    {
        $brand = Brand::create($this->validated($request) + ['company_id' => $this->companyId($request)]);

        ActivityLogger::log('brand.created', "Membuat brand (API): {$brand->code}", $brand);

        return response()->json(['data' => $brand], 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeCompany($request, $brand->company_id);

        $brand->update($this->validated($request, $brand->id));

        ActivityLogger::log('brand.updated', "Mengubah brand (API): {$brand->code}", $brand);

        return response()->json(['data' => $brand->fresh()]);
    }

    public function destroy(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeCompany($request, $brand->company_id);

        if ($brand->products()->exists()) {
            return response()->json(['message' => 'Brand is still used by products.'], 422);
        }

        $brand->delete();

        ActivityLogger::log('brand.deleted', "Menghapus brand (API): {$brand->code}", $brand);

        return response()->json(['message' => 'Brand deleted.']);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('brands', 'code')->where('company_id', $this->companyId($request))->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
