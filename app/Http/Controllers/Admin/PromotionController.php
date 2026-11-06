<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionController extends Controller
{
    use AuthorizesCompany;

    public function index(): View
    {
        return view('admin.products.promotions.index', [
            'promotions' => apply_sort(
                Promotion::with('product', 'bonusProduct'),
                request('sort'), request('dir'),
                ['code', 'name', 'type', 'value', 'is_active', 'starts_at', 'ends_at', 'created_at'],
                'created_at'
            )->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.promotions.form', [
            'promotion' => new Promotion,
            'products' => Product::orderBy('sku')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $promotion = Promotion::create($this->validated($request) + ['company_id' => current_company_id()]);

        ActivityLogger::log('promotion.created', "Membuat promosi: {$promotion->code} · {$promotion->name}", $promotion);

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion created successfully.');
    }

    public function edit(Promotion $promotion): View
    {
        $this->ensureCompany($promotion->company_id);
        $this->denyIfFromSap($promotion);

        return view('admin.products.promotions.form', [
            'promotion' => $promotion,
            'products' => Product::orderBy('sku')->get(),
        ]);
    }

    public function update(Request $request, Promotion $promotion)
    {
        $this->ensureCompany($promotion->company_id);
        $this->denyIfFromSap($promotion);

        $promotion->update($this->validated($request, $promotion->id));

        ActivityLogger::log('promotion.updated', "Mengubah promosi: {$promotion->code} · {$promotion->name}", $promotion);

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion updated successfully.');
    }

    public function destroy(Promotion $promotion)
    {
        $this->ensureCompany($promotion->company_id);
        $this->denyIfFromSap($promotion);

        $label = $promotion->code.' · '.$promotion->name;
        $promotion->delete();

        ActivityLogger::log('promotion.deleted', "Menghapus promosi: {$label}", $promotion);

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion deleted successfully.');
    }

    /**
     * Diskon dari SAP B1 hanya-baca; ubah di SAP lalu sinkronkan.
     */
    private function denyIfFromSap(Promotion $promotion): void
    {
        abort_if($promotion->isFromSap(), 403, 'Discounts from SAP B1 are read-only; change them in SAP B1 and sync.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate(Promotion::rules(current_company_id(), $id, $request->input('type')));

        return Promotion::normalize($data + ['is_active' => $request->boolean('is_active')]);
    }
}
