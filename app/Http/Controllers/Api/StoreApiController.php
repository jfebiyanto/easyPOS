<?php

namespace App\Http\Controllers\Api;

use App\Models\Store;
use App\Rules\ValidStoreCurrency;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Store::where('company_id', $this->companyId($request))->withCount('users')->orderBy('code')->get(),
        ]);
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeCompany($request, $store->company_id);

        return response()->json(['data' => $store->loadCount('users')]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperuser($request);

        $store = Store::create($this->validated($request) + [
            'company_id' => $this->companyId($request),
            'is_active' => $request->boolean('is_active', true),
        ]);

        ActivityLogger::log('store.created', "Created new store (API): {$store->code} · {$store->name}", $store);

        return response()->json(['data' => $store], 201);
    }

    public function update(Request $request, Store $store): JsonResponse
    {
        $this->ensureSuperuser($request);
        $this->authorizeCompany($request, $store->company_id);

        $store->update($this->validated($request, $store->id) + ['is_active' => $request->boolean('is_active')]);

        ActivityLogger::log('store.updated', "Mengubah store (API): {$store->code} · {$store->name}", $store);

        return response()->json(['data' => $store->fresh()]);
    }

    public function destroy(Request $request, Store $store): JsonResponse
    {
        $this->ensureSuperuser($request);
        $this->authorizeCompany($request, $store->company_id);

        if ($store->users()->exists()) {
            return response()->json(['message' => 'Store still has users and cannot be deleted.'], 422);
        }

        if ($store->hasStockTransferDocuments()) {
            return response()->json(['message' => 'Store has stock transfer documents and cannot be deleted.'], 422);
        }

        ActivityLogger::log('store.deleted', "Menghapus store (API): {$store->code} · {$store->name}", $store);
        $store->delete();

        return response()->json(['message' => 'Store deleted.']);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('stores', 'code')->where('company_id', $this->companyId($request))->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'pic_name' => ['required', 'string', 'max:150'],
            'pic_phone' => ['nullable', 'string', 'max:30'],
            'ppn_enabled' => ['sometimes', 'boolean'],
            'ppn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => [
                'required', 'string', 'max:10',
                new ValidStoreCurrency($id ? Store::whereKey($id)->value('currency') : null),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['ppn_enabled'] = $request->boolean('ppn_enabled');
        $data['ppn_rate'] = $data['ppn_enabled'] ? ($data['ppn_rate'] ?? 11) : 0;
        $data['currency'] = strtoupper(trim($data['currency']));

        return $data;
    }
}
