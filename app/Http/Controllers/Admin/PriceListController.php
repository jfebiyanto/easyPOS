<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PriceListController extends Controller
{
    use AuthorizesSuperuser;

    public function index(): View
    {
        return view('admin.products.price_lists.index', [
            'priceLists' => apply_sort(
                PriceList::withCount('items'),
                request('sort'), request('dir'),
                ['code', 'name', 'items_count', 'is_active', 'created_at'],
                'created_at'
            )->paginate(10),
        ]);
    }

    public function create(): View
    {
        $this->ensureSuperuser(request());

        return view('admin.products.price_lists.form', ['priceList' => new PriceList()]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperuser($request);
        $data = $this->validated($request);

        $priceList = PriceList::create($data + ['store_id' => active_store_id()]);

        ActivityLogger::log('price_list.created', "Membuat price list: {$priceList->code} · {$priceList->name}", $priceList, $data);

        return redirect()->route('admin.price-lists.index')->with('success', 'Price list berhasil dibuat.');
    }

    public function edit(PriceList $priceList): View
    {
        $this->ensureSuperuser(request());

        return view('admin.products.price_lists.form', ['priceList' => $priceList]);
    }

    public function update(Request $request, PriceList $priceList)
    {
        $this->ensureSuperuser($request);
        $priceList->update($this->validated($request, $priceList->id));

        ActivityLogger::log('price_list.updated', "Mengubah price list: {$priceList->code} · {$priceList->name}", $priceList);

        return redirect()->route('admin.price-lists.index')->with('success', 'Price list berhasil diperbarui.');
    }

    public function show(PriceList $priceList): View
    {
        return view('admin.products.price_lists.items', [
            'priceList' => $priceList->load('items.product'),
            'products' => Product::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeItem(Request $request, PriceList $priceList)
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

        $product = Product::find($data['product_id']);

        ActivityLogger::log('price_list.item_changed', "Mengubah harga {$product?->sku} menjadi Rp ".number_format($data['price'], 0, ',', '.')." di price list {$priceList->code}", $item, [
            'price_list_id' => $priceList->id, 'product_id' => $data['product_id'], 'price' => $data['price'], 'min_qty' => $data['min_qty'],
        ]);

        return redirect()->route('admin.price-lists.show', $priceList)->with('success', 'Item harga berhasil disimpan.');
    }

    public function destroyItem(PriceList $priceList, PriceListItem $item)
    {
        $this->ensureSuperuser(request());

        $label = "Item {$item->product?->sku} dari price list {$priceList->code}";
        $item->delete();

        ActivityLogger::log('price_list.item_deleted', "Menghapus {$label}", $item, ['price_list_id' => $priceList->id]);

        return redirect()->route('admin.price-lists.show', $priceList)->with('success', 'Item harga berhasil dihapus.');
    }

    public function destroy(PriceList $priceList)
    {
        $this->ensureSuperuser(request());

        $label = $priceList->code.' · '.$priceList->name;
        $priceList->delete();

        ActivityLogger::log('price_list.deleted', "Menghapus price list: {$label}", $priceList);

        return redirect()->route('admin.price-lists.index')->with('success', 'Price list berhasil dihapus.');
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