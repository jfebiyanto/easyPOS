<?php

namespace App\Http\Controllers\Api;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PriceListApiController extends ApiController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => PriceList::withCount('items')->get()]);
    }

    public function show(PriceList $priceList): JsonResponse
    {
        return response()->json(['data' => $priceList->load(['items.product'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperuser($request);

        $priceList = PriceList::create($this->validated($request) + ['store_id' => active_store_id()]);

        ActivityLogger::log('price_list.created', "Membuat price list (API): {$priceList->code}", $priceList);

        return response()->json(['data' => $priceList], 201);
    }

    public function update(Request $request, PriceList $priceList): JsonResponse
    {
        $this->ensureSuperuser($request);

        $priceList->update($this->validated($request, $priceList->id));

        ActivityLogger::log('price_list.updated', "Mengubah price list (API): {$priceList->code}", $priceList);

        return response()->json(['data' => $priceList->fresh()]);
    }

    public function destroy(Request $request, PriceList $priceList): JsonResponse
    {
        $this->ensureSuperuser($request);

        $label = $priceList->code;
        $priceList->delete();

        ActivityLogger::log('price_list.deleted', "Menghapus price list (API): {$label}", $priceList);

        return response()->json(['message' => 'Price list dihapus.']);
    }

    public function items(PriceList $priceList): JsonResponse
    {
        return response()->json(['data' => $priceList->items()->with('product')->get()]);
    }

    public function storeItem(Request $request, PriceList $priceList): JsonResponse
    {
        $this->ensureSuperuser($request);

        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'min_qty' => ['required', 'integer', 'min:1'],
        ]);

        $item = $priceList->items()->updateOrCreate(
            ['product_id' => $data['product_id'], 'min_qty' => $data['min_qty']],
            ['price' => $data['price']]
        );

        ActivityLogger::log('price_list.item_changed', "Mengubah harga (API) produk {$data['product_id']} menjadi Rp {$data['price']} di {$priceList->code}", $item, $data);

        return response()->json(['data' => $item->fresh('product')], 201);
    }

    public function destroyItem(Request $request, PriceList $priceList, PriceListItem $item): JsonResponse
    {
        $this->ensureSuperuser($request);

        $item->delete();

        ActivityLogger::log('price_list.item_deleted', "Menghapus item harga (API) dari {$priceList->code}", $item);

        return response()->json(['message' => 'Item harga dihapus.']);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('price_lists', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'is_default' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}