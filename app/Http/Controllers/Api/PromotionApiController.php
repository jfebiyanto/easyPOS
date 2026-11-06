<?php

namespace App\Http\Controllers\Api;

use App\Models\Promotion;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Promotion::where('company_id', $this->companyId($request))->with('product', 'bonusProduct');

        if ($request->boolean('only_active')) {
            $query->activeAt(now());
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorizeCompany($request, $promotion->company_id);

        return response()->json(['data' => $promotion->load('product', 'bonusProduct')]);
    }

    public function store(Request $request): JsonResponse
    {
        $promotion = Promotion::create($this->validated($request) + ['company_id' => $this->companyId($request)]);

        ActivityLogger::log('promotion.created', "Membuat promosi (API): {$promotion->code}", $promotion);

        return response()->json(['data' => $promotion], 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorizeCompany($request, $promotion->company_id);

        $promotion->update($this->validated($request, $promotion->id));

        ActivityLogger::log('promotion.updated', "Mengubah promosi (API): {$promotion->code}", $promotion);

        return response()->json(['data' => $promotion->fresh()]);
    }

    public function destroy(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorizeCompany($request, $promotion->company_id);

        $label = $promotion->code;
        $promotion->delete();

        ActivityLogger::log('promotion.deleted', "Menghapus promosi (API): {$label}", $promotion);

        return response()->json(['message' => 'Promotion deleted.']);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return Promotion::normalize($request->validate(
            Promotion::rules($this->companyId($request), $id, $request->input('type'))
        ));
    }
}
