<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master Customer Group. Grup dari SAP B1 hanya-baca (kode, nama, status); penanda default
 * tetap dapat diatur di easyPOS. Grup default menjadi nilai awal saat membuat customer baru.
 */
class CustomerGroupController extends Controller
{
    use AuthorizesCompany;
    use AuthorizesSuperuser;

    public function index(Request $request): View
    {
        $this->ensureSuperuser($request);

        return view('admin.customer-groups.index', [
            'groups' => apply_sort(
                CustomerGroup::where('company_id', current_company_id())->withCount('customers'),
                $request->query('sort'), $request->query('dir'),
                ['code', 'name', 'source', 'is_default', 'is_active'],
                'code', 'asc'
            )->paginate(15)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->ensureSuperuser($request);

        return view('admin.customer-groups.form', ['group' => new CustomerGroup(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureSuperuser($request);

        $data = $this->validated($request);
        $group = DB::transaction(function () use ($data, $request) {
            $group = CustomerGroup::create($data + [
                'company_id' => current_company_id(),
                'source' => CustomerGroup::SOURCE_MANUAL,
            ]);
            $this->applyDefault($group, $request->boolean('is_default'));

            return $group;
        });

        ActivityLogger::log('customer_group.created', "Membuat Customer Group: {$group->code}", $group);

        return redirect()->route('admin.customer-groups.index')->with('success', 'Customer Group berhasil dibuat.');
    }

    public function edit(Request $request, CustomerGroup $customerGroup): View
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($customerGroup->company_id);

        return view('admin.customer-groups.form', ['group' => $customerGroup]);
    }

    public function update(Request $request, CustomerGroup $customerGroup): RedirectResponse
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($customerGroup->company_id);

        DB::transaction(function () use ($request, $customerGroup) {
            if (! $customerGroup->isFromSap()) {
                $customerGroup->update($this->validated($request, $customerGroup->id));
            }
            $this->applyDefault($customerGroup, $request->boolean('is_default'));
        });

        ActivityLogger::log('customer_group.updated', "Mengubah Customer Group: {$customerGroup->code}", $customerGroup);

        return redirect()->route('admin.customer-groups.index')->with('success', 'Customer Group berhasil diperbarui.');
    }

    public function destroy(Request $request, CustomerGroup $customerGroup): RedirectResponse
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($customerGroup->company_id);
        abort_if($customerGroup->isFromSap(), 403, 'Customer Group dari SAP B1 tidak dapat dihapus.');

        if (Customer::where('customer_group_id', $customerGroup->id)->exists()) {
            return back()->with('error', 'Customer Group masih dipakai customer, nonaktifkan saja.');
        }

        $code = $customerGroup->code;
        $customerGroup->delete();

        ActivityLogger::log('customer_group.deleted', "Menghapus Customer Group: {$code}", $customerGroup);

        return redirect()->route('admin.customer-groups.index')->with('success', 'Customer Group berhasil dihapus.');
    }

    /**
     * Hanya satu grup default per perusahaan; grup default harus aktif.
     */
    private function applyDefault(CustomerGroup $group, bool $isDefault): void
    {
        if ($isDefault) {
            abort_unless($group->is_active, 422, 'Grup nonaktif tidak dapat dijadikan default.');

            CustomerGroup::where('company_id', $group->company_id)->whereKeyNot($group->id)->update(['is_default' => false]);
        }

        $group->forceFill(['is_default' => $isDefault])->save();
    }

    /**
     * @return array{code: string, name: string, is_active: bool}
     */
    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('customer_groups', 'code')->where('company_id', current_company_id())->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
