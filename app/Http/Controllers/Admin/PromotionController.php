<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function index(): View
    {
        return view('admin.products.promotions.index', [
            'promotions' => apply_sort(
                Promotion::with('product'),
                request('sort'), request('dir'),
                ['code', 'name', 'type', 'value', 'is_active', 'starts_at', 'ends_at', 'created_at'],
                'created_at'
            )->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.promotions.form', [
            'promotion' => new Promotion(),
            'products' => Product::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $promotion = Promotion::create($this->validated($request) + ['store_id' => active_store_id()]);

        ActivityLogger::log('promotion.created', "Membuat promosi: {$promotion->code} · {$promotion->name}", $promotion);

        return redirect()->route('admin.promotions.index')->with('success', 'Promosi berhasil dibuat.');
    }

    public function edit(Promotion $promotion): View
    {
        return view('admin.products.promotions.form', [
            'promotion' => $promotion,
            'products' => Product::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Promotion $promotion)
    {
        $promotion->update($this->validated($request, $promotion->id));

        ActivityLogger::log('promotion.updated', "Mengubah promosi: {$promotion->code} · {$promotion->name}", $promotion);

        return redirect()->route('admin.promotions.index')->with('success', 'Promosi berhasil diperbarui.');
    }

    public function destroy(Promotion $promotion)
    {
        $label = $promotion->code.' · '.$promotion->name;
        $promotion->delete();

        ActivityLogger::log('promotion.deleted', "Menghapus promosi: {$label}", $promotion);

        return redirect()->route('admin.promotions.index')->with('success', 'Promosi berhasil dihapus.');
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