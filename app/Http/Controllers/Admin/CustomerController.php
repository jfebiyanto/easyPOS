<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\MemberLevel;
use App\Support\ActivityLogger;
use App\Support\LoyaltyService;
use App\Support\Sap\SapCustomerSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $query = Customer::with('memberLevel');

        if ($q = $request->query('q')) {
            $query->where(fn ($x) => $x->whereLike('name', "%{$q}%")
                ->orWhereLike('phone', "%{$q}%")
                ->orWhereLike('code', "%{$q}%"));
        }
        if ($level = $request->query('level')) {
            $query->where('member_level_id', $level);
        }

        $query = apply_sort(
            $query,
            $request->query('sort'), $request->query('dir'),
            ['name', 'code', 'phone', 'points_balance', 'is_active', 'created_at'],
            'code', 'asc'
        );

        return view('admin.customers.index', [
            'customers' => $query->paginate(15)->withQueryString(),
            'levels' => MemberLevel::where('is_active', true)->orderBy('order')->get(),
        ]);
    }

    public function create(): View
    {
        // Grup default menjadi pilihan awal customer baru.
        return view('admin.customers.form', $this->formData(new Customer([
            'customer_group_id' => CustomerGroup::defaultFor(current_company_id())?->id,
        ])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['company_id'] = current_company_id();
        $data['is_member'] = $request->boolean('is_member', true);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['joined_at'] = now();
        $data['member_level_id'] = LoyaltyService::levelFor(current_company_id(), 0)?->id;

        if ($this->sapActive()) {
            // Kode customer = CardCode yang dibuat SAP B1 setelah customer terkirim.
            $data['code'] = null;
        } elseif (blank($data['code'] ?? null)) {
            $data['code'] = 'CUST-'.str_pad((string) (Customer::where('company_id', $data['company_id'])->count() + 1), 5, '0', STR_PAD_LEFT);
        }

        $customer = Customer::create($data + ['source' => Customer::SOURCE_MANUAL]);

        ActivityLogger::log('customer.created', "Menambah customer: {$customer->name} ({$customer->code})", $customer);

        SapCustomerSync::queue($customer);

        return redirect()->route('admin.customers.index')->with('success', 'Customer created successfully.');
    }

    public function show(Customer $customer): View
    {
        return view('admin.customers.show', [
            'customer' => $customer->load('memberLevel'),
            'ledger' => $customer->loyaltyLedger()->latest('created_at')->limit(50)->get(),
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('admin.customers.form', $this->formData($customer));
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $customer->isFromSap() ? [] : $this->validated($request, $customer->id);
        $data['is_member'] = $request->boolean('is_member');
        if (! $customer->isFromSap()) {
            $data['is_active'] = $request->boolean('is_active');
        }

        // Kode customer tersinkron = CardCode SAP B1, tidak dapat diubah.
        if ($this->sapActive()) {
            unset($data['code']);
        }

        // Pelanggan retail default gerai tidak boleh menjadi member.
        if (LoyaltyService::isRetailDefault($customer->id)) {
            $data['is_member'] = false;
        }

        $customer->fill($data);
        $sapFieldsChanged = $customer->isDirty(Customer::SAP_FIELDS);
        $customer->save();

        ActivityLogger::log('customer.updated', "Mengubah customer: {$customer->name}", $customer);

        if ($sapFieldsChanged || $customer->sap_sync_status === Customer::SYNC_FAILED) {
            SapCustomerSync::queue($customer);
        }

        return redirect()->route('admin.customers.index')->with('success', 'Customer updated successfully.');
    }

    /**
     * Kirim ulang customer manual yang gagal tersinkron ke SAP B1.
     */
    public function syncSap(Customer $customer): RedirectResponse
    {
        abort_if($customer->isFromSap() || ! $this->sapActive(), 404);

        SapCustomerSync::queue($customer);

        return back()->with('success', 'Customer scheduled to be sent to SAP B1.');
    }

    public function adjustPoints(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'points' => ['required', 'numeric'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        LoyaltyService::adjust($customer, (float) $data['points'], $data['note'], (string) $request->user()->id);

        ActivityLogger::log('customer.points_adjusted', "Penyesuaian poin {$customer->name} ({$data['points']})", $customer);

        return redirect()->route('admin.customers.show', $customer)->with('success', 'Points adjusted successfully.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'code' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'customer_group_id' => ['nullable', Rule::exists('customer_groups', 'id')
                ->where('company_id', current_company_id())
                ->where(fn ($q) => $this->sapActive() ? $q->where('source', CustomerGroup::SOURCE_SAP) : $q)],
            'is_member' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        abort_if(blank($data['phone'] ?? null) && blank($data['code'] ?? null), 422, 'Mobile number or customer code is required.');

        $companyId = current_company_id();

        if (filled($data['phone'] ?? null)) {
            $phoneQuery = Customer::where('company_id', $companyId)->where('phone', $data['phone']);
            if ($id) {
                $phoneQuery->whereKeyNot($id);
            }
            abort_if($phoneQuery->exists(), 422, 'Mobile number is already registered.');
        }
        if (filled($data['code'] ?? null)) {
            $codeQuery = Customer::where('company_id', $companyId)->where('code', $data['code']);
            if ($id) {
                $codeQuery->whereKeyNot($id);
            }
            abort_if($codeQuery->exists(), 422, 'Customer code is already in use.');
        }

        return $data;
    }

    /**
     * @return array{customer: Customer, groups: Collection<int, CustomerGroup>, sapActive: bool}
     */
    private function formData(Customer $customer): array
    {
        $sapActive = $this->sapActive();

        return [
            'customer' => $customer,
            'sapActive' => $sapActive,
            // Selama integrasi aktif hanya grup dari SAP yang dapat dipilih (GroupCode BP).
            'groups' => CustomerGroup::where('company_id', current_company_id())
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $customer->customer_group_id))
                ->when($sapActive, fn ($q) => $q->where(fn ($w) => $w->where('source', CustomerGroup::SOURCE_SAP)->orWhere('id', $customer->customer_group_id)))
                ->orderBy('name')
                ->get(),
        ];
    }

    private function sapActive(): bool
    {
        return (bool) CompanyDetail::find(current_company_id())?->sapIntegrationActive();
    }
}
