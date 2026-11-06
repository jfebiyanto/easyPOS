<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\SaleLine;
use App\Models\TaxGroup;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master Tax Group (Output Tax). Satu interface untuk SAP maupun non-SAP:
 * Tax Group dari SAP hanya-baca, yang manual bisa diubah.
 */
class TaxGroupController extends Controller
{
    use AuthorizesCompany;
    use AuthorizesSuperuser;

    public function index(Request $request): View
    {
        return view('admin.tax-groups.index', [
            'taxGroups' => apply_sort(
                TaxGroup::query(),
                $request->query('sort'), $request->query('dir'),
                ['code', 'name', 'rate', 'source', 'is_active'],
                'code', 'asc'
            )->paginate(10),
            'selectedId' => CompanyDetail::current()->tax_group_id,
        ]);
    }

    public function create(Request $request): View
    {
        $this->ensureSuperuser($request);

        return view('admin.tax-groups.form', ['taxGroup' => new TaxGroup]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperuser($request);

        $taxGroup = TaxGroup::create($this->validated($request) + [
            'company_id' => current_company_id(),
            'source' => TaxGroup::SOURCE_MANUAL,
        ]);

        ActivityLogger::log('tax_group.created', "Membuat Tax Group: {$taxGroup->code}", $taxGroup);

        return redirect()->route('admin.tax-groups.index')->with('success', 'Tax Group created successfully.');
    }

    public function edit(Request $request, TaxGroup $taxGroup): View
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($taxGroup->company_id);

        return view('admin.tax-groups.form', ['taxGroup' => $taxGroup]);
    }

    public function update(Request $request, TaxGroup $taxGroup)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($taxGroup->company_id);
        abort_if($taxGroup->isFromSap(), 403, 'A Tax Group from SAP B1 cannot be changed.');

        $taxGroup->update($this->validated($request, $taxGroup->id));
        self::syncCompanyRate($taxGroup);

        ActivityLogger::log('tax_group.updated', "Mengubah Tax Group: {$taxGroup->code}", $taxGroup);

        return redirect()->route('admin.tax-groups.index')->with('success', 'Tax Group updated successfully.');
    }

    public function destroy(Request $request, TaxGroup $taxGroup)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($taxGroup->company_id);
        abort_if($taxGroup->isFromSap(), 403, 'A Tax Group from SAP B1 cannot be deleted.');

        if (CompanyDetail::where('tax_group_id', $taxGroup->id)->exists()
            || SaleLine::where('tax_group_code', $taxGroup->code)->exists()) {
            return back()->with('error', 'Tax Group is already in use; deactivate it instead.');
        }

        $code = $taxGroup->code;
        $taxGroup->delete();

        ActivityLogger::log('tax_group.deleted', "Menghapus Tax Group: {$code}", $taxGroup);

        return redirect()->route('admin.tax-groups.index')->with('success', 'Tax Group deleted successfully.');
    }

    /**
     * Samakan tarif PPN perusahaan bila Tax Group ini yang sedang dipakai.
     */
    public static function syncCompanyRate(TaxGroup $taxGroup): void
    {
        CompanyDetail::where('tax_group_id', $taxGroup->id)->update(['ppn_rate' => $taxGroup->rate]);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('tax_groups', 'code')->where('company_id', current_company_id())->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
