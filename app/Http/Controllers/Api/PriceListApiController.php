<?php

namespace App\Http\Controllers\Api;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PriceListApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => PriceList::where('company_id', $this->companyId($request))
                ->withCount('items')
                ->get(),
        ]);
    }

    public function show(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizeCompany($request, $priceList->company_id);

        return response()->json(['data' => $priceList->load(['items.product'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $priceList = PriceList::create($this->validated($request) + ['company_id' => $this->companyId($request)]);

        ActivityLogger::log('price_list.created', "Membuat price list (API): {$priceList->code}", $priceList);

        return response()->json(['data' => $priceList], 201);
    }

    public function update(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizeCompany($request, $priceList->company_id);

        $priceList->update($this->validated($request, $priceList->id));

        ActivityLogger::log('price_list.updated', "Mengubah price list (API): {$priceList->code}", $priceList);

        return response()->json(['data' => $priceList->fresh()]);
    }

    public function destroy(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizeCompany($request, $priceList->company_id);

        $label = $priceList->code;
        $priceList->delete();

        ActivityLogger::log('price_list.deleted', "Menghapus price list (API): {$label}", $priceList);

        return response()->json(['message' => 'Price list deleted.']);
    }

    public function items(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizeCompany($request, $priceList->company_id);

        return response()->json(['data' => $priceList->items()->with('product')->get()]);
    }

    public function storeItem(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizeCompany($request, $priceList->company_id);

        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $priceList->company_id)],
            'price' => ['required', 'numeric', 'min:0'],
            'min_qty' => ['required', 'integer', 'min:1'],
        ]);

        abort_unless(
            Product::whereKey($data['product_id'])->where('company_id', $priceList->company_id)->exists(),
            422,
            'The product does not belong to the same company as the price list.'
        );

        $item = $priceList->items()->updateOrCreate(
            ['product_id' => $data['product_id'], 'min_qty' => $data['min_qty']],
            ['price' => $data['price']]
        );

        ActivityLogger::log('price_list.item_changed', "Changed price (API) of product {$data['product_id']} to Rp {$data['price']} in {$priceList->code}", $item, $data);

        return response()->json(['data' => $item->fresh('product')], 201);
    }

    public function destroyItem(Request $request, PriceList $priceList, PriceListItem $item): JsonResponse
    {
        $this->authorizeCompany($request, $priceList->company_id);

        abort_unless($item->price_list_id === $priceList->id, 404);

        $item->delete();

        ActivityLogger::log('price_list.item_deleted', "Deleted price item (API) from {$priceList->code}", $item);

        return response()->json(['message' => 'Price item deleted.']);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'store_id' => ['nullable', Rule::exists('stores', 'id')->where('company_id', $this->companyId($request))],
            'code' => ['required', 'string', 'max:30', Rule::unique('price_lists', 'code')->where('company_id', $this->companyId($request))->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'is_default' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
