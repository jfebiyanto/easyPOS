<?php

namespace App\Http\Controllers\Api;

use App\Models\Promotion;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Promotion::query()->with('product');

        if ($request->boolean('only_active')) {
            $query->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return response()->json(['data' => $promotion->load('product')]);
    }

    public function store(Request $request): JsonResponse
    {
        $promotion = Promotion::create($this->validated($request) + ['store_id' => active_store_id()]);

        ActivityLogger::log('promotion.created', "Membuat promosi (API): {$promotion->code}", $promotion);

        return response()->json(['data' => $promotion], 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $promotion->update($this->validated($request, $promotion->id));

        ActivityLogger::log('promotion.updated', "Mengubah promosi (API): {$promotion->code}", $promotion);

        return response()->json(['data' => $promotion->fresh()]);
    }

    public function destroy(Request $request, Promotion $promotion): JsonResponse
    {
        $label = $promotion->code;
        $promotion->delete();

        ActivityLogger::log('promotion.deleted', "Menghapus promosi (API): {$label}", $promotion);

        return response()->json(['message' => 'Promosi dihapus.']);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('promotions', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(Promotion::TYPES))],
            'value' => ['required', 'numeric', 'min:0'],
            'product_id' => ['nullable', 'exists:products,id'],
            'min_qty' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
    }
}