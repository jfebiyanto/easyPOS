<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Promotion;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Hierarki promosi: urutan sumber diskon. Pada satu baris transaksi, sumber paling atas yang
 * menghasilkan diskon yang menang; nilainya tidak dibandingkan dengan sumber di bawahnya.
 */
class PromotionHierarchyController extends Controller
{
    use AuthorizesSuperuser;

    public function edit(Request $request): View
    {
        $this->ensureSuperuser($request);

        return view('admin.products.promotions.hierarchy', [
            'order' => CompanyDetail::current()->promotionHierarchy(),
            'sources' => Promotion::HIERARCHY_SOURCES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensureSuperuser($request);

        $known = array_keys(Promotion::HIERARCHY_SOURCES);
        $data = $request->validate([
            'order' => ['required', 'array', 'size:'.count($known)],
            'order.*' => ['required', 'distinct', Rule::in($known)],
        ]);

        CompanyDetail::current()->update(['promotion_hierarchy' => array_values($data['order'])]);

        ActivityLogger::log('promotion.hierarchy_updated', 'Mengubah hierarki promosi: '.implode(' > ', $data['order']));

        return redirect()->route('admin.promotion-hierarchy.edit')->with('success', 'Hierarki promosi disimpan.');
    }
}
