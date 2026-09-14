<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MemberLevel;
use App\Support\ActivityLogger;
use App\Support\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $query = Customer::with('memberLevel')->withCount('sales');

        if ($q = $request->query('q')) {
            $query->where(fn ($x) => $x->where('name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%"));
        }
        if ($level = $request->query('level')) {
            $query->where('member_level_id', $level);
        }

        $query = apply_sort(
            $query,
            $request->query('sort'), $request->query('dir'),
            ['name', 'code', 'phone', 'points_balance', 'is_active', 'created_at'],
            'created_at'
        );

        return view('admin.customers.index', [
            'customers' => $query->paginate(15)->withQueryString(),
            'levels' => MemberLevel::where('is_active', true)->orderBy('order')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.customers.form', ['customer' => new Customer()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['company_id'] = current_company_id();
        $data['is_member'] = $request->boolean('is_member', true);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['joined_at'] = now();
        $data['member_level_id'] = LoyaltyService::levelFor(current_company_id(), 0)?->id;

        if (blank($data['code'] ?? null)) {
            $data['code'] = 'CUST-'.str_pad((string) (Customer::where('company_id', $data['company_id'])->count() + 1), 5, '0', STR_PAD_LEFT);
        }

        $customer = Customer::create($data);

        ActivityLogger::log('customer.created', "Menambah customer: {$customer->name} ({$customer->code})", $customer);

        return redirect()->route('admin.customers.index')->with('success', 'Customer berhasil dibuat.');
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
        return view('admin.customers.form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $this->validated($request, $customer->id);
        $data['is_member'] = $request->boolean('is_member');
        $data['is_active'] = $request->boolean('is_active');

        $customer->update($data);

        ActivityLogger::log('customer.updated', "Mengubah customer: {$customer->name}", $customer);

        return redirect()->route('admin.customers.index')->with('success', 'Customer berhasil diperbarui.');
    }

    public function adjustPoints(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'points' => ['required', 'numeric'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        LoyaltyService::adjust($customer, (float) $data['points'], $data['note'], (string) $request->user()->id);

        ActivityLogger::log('customer.points_adjusted', "Penyesuaian poin {$customer->name} ({$data['points']})", $customer);

        return redirect()->route('admin.customers.show', $customer)->with('success', 'Poin berhasil disesuaikan.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'code' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_member' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        abort_if(blank($data['phone'] ?? null) && blank($data['code'] ?? null), 422, 'Nomor HP atau kode customer wajib diisi.');

        $companyId = current_company_id();

        if (filled($data['phone'] ?? null)) {
            $phoneQuery = Customer::where('company_id', $companyId)->where('phone', $data['phone']);
            if ($id) {
                $phoneQuery->whereKeyNot($id);
            }
            abort_if($phoneQuery->exists(), 422, 'Nomor HP sudah terdaftar.');
        }
        if (filled($data['code'] ?? null)) {
            $codeQuery = Customer::where('company_id', $companyId)->where('code', $data['code']);
            if ($id) {
                $codeQuery->whereKeyNot($id);
            }
            abort_if($codeQuery->exists(), 422, 'Kode customer sudah dipakai.');
        }

        return $data;
    }
}
