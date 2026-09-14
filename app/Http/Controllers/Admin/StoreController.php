<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Currency;
use App\Models\PriceList;
use App\Models\Store;
use App\Models\User;
use App\Rules\ValidStoreCurrency;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StoreController extends Controller
{
    use AuthorizesSuperuser, AuthorizesCompany;

    public function index(): View
    {
        $this->ensureSuperuser(request());

        return view('admin.stores.index', [
            'stores' => apply_sort(
                Store::withCount('users'),
                request('sort'), request('dir'),
                ['code', 'name', 'pic_name', 'ppn_enabled', 'users_count', 'created_at'],
                'code', 'asc'
            )->paginate(10),
            'activeStoreId' => session('active_store_id'),
        ]);
    }

    public function create(): View
    {
        $this->ensureSuperuser(request());

        return view('admin.stores.form', [
            'store' => new Store,
            'company' => CompanyDetail::current(),
            'currencies' => $this->currencyOptions(),
            'priceLists' => $this->priceListOptions(),
            'assigned' => [],
            'defaultPriceListId' => null,
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperuser($request);

        $company = CompanyDetail::current();
        $data = $this->validated($request);

        $data['company_id'] = $company->id;
        $data['ppn_enabled'] = $company->ppn_enabled ? $request->boolean('ppn_enabled', $company->ppn_enabled) : $request->boolean('ppn_enabled');
        $data['ppn_rate'] = $data['ppn_enabled']
            ? ($request->filled('ppn_rate') ? $data['ppn_rate'] : $company->ppn_rate)
            : 0;
        $data['is_active'] = $request->boolean('is_active', true);

        $store = Store::create($data);
        $this->syncPriceLists($store, $request);

        if (! $request->session()->has('active_store_id')) {
            $request->session()->put('active_store_id', $store->id);
        }

        ActivityLogger::log('store.created', "Membuat store baru: {$store->code} · {$store->name}", $store);

        return redirect()->route('admin.stores.index')->with('success', 'Store berhasil dibuat.');
    }

    public function edit(Store $store): View
    {
        $this->ensureSuperuser(request());
        $this->ensureCompany($store->company_id);

        return view('admin.stores.form', [
            'store' => $store,
            'company' => CompanyDetail::current(),
            'currencies' => $this->currencyOptions($store->currency),
            'priceLists' => $this->priceListOptions(),
            'assigned' => $store->priceLists()->pluck('price_lists.id')->all(),
            'defaultPriceListId' => $store->priceLists()->wherePivot('is_default', true)->value('price_lists.id'),
        ]);
    }

    public function update(Request $request, Store $store)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($store->company_id);

        $data = $this->validated($request, $store->id);
        $data['is_active'] = $request->boolean('is_active');

        $store->update($data);
        $this->syncPriceLists($store, $request);

        ActivityLogger::log('store.updated', "Mengubah store: {$store->code} · {$store->name}", $store);

        return redirect()->route('admin.stores.index')->with('success', 'Konfigurasi store berhasil diperbarui.');
    }

    public function destroy(Store $store)
    {
        $this->ensureSuperuser(request());
        $this->ensureCompany($store->company_id);

        if (User::where('store_id', $store->id)->exists()) {
            return back()->with('error', 'Store masih memiliki user, tidak dapat dihapus.');
        }

        $store->priceLists()->detach();
        $store->delete();

        if (session('active_store_id') === $store->id) {
            session()->put('active_store_id', Store::query()->orderBy('code')->value('id'));
        }

        ActivityLogger::log('store.deleted', "Menghapus store: {$store->code} · {$store->name}", $store);

        return redirect()->route('admin.stores.index')->with('success', 'Store berhasil dihapus.');
    }

    public function switch(Request $request, Store $store)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($store->company_id);

        $request->session()->put('active_store_id', $store->id);

        ActivityLogger::log('store.switched', "Mengganti store aktif menjadi: {$store->code} · {$store->name}", $store);

        return redirect()->intended(route('admin.stores.index'))
            ->with('success', 'Store aktif: '.$store->code.' · '.$store->name);
    }

    private function syncPriceLists(Store $store, Request $request): void
    {
        $ids = collect($request->input('price_list_ids', []))->filter()->all();
        $defaultId = $request->input('default_price_list_id');

        $sync = collect($ids)->mapWithKeys(fn ($id) => [
            $id => ['is_default' => (string) $id === (string) $defaultId],
        ])->all();

        $store->priceLists()->sync($sync);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('stores', 'code')->where('company_id', current_company_id())->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'pic_name' => ['required', 'string', 'max:150'],
            'pic_phone' => ['nullable', 'string', 'max:30'],
            'ppn_enabled' => ['sometimes', 'boolean'],
            'ppn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => [
                'required', 'string', 'max:10',
                new ValidStoreCurrency($id ? Store::whereKey($id)->value('currency') : null),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'price_list_ids' => ['nullable', 'array'],
            'price_list_ids.*' => ['string', Rule::exists('price_lists', 'id')->where('company_id', current_company_id())],
            'default_price_list_id' => ['nullable', 'string', Rule::exists('price_lists', 'id')->where('company_id', current_company_id())],
        ]);

        $data['currency'] = strtoupper(trim($data['currency']));

        return $data;
    }

    private function priceListOptions(): \Illuminate\Support\Collection
    {
        return PriceList::where('company_id', current_company_id())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'store_id']);
    }

    private function currencyOptions(?string $currentCode = null): array
    {
        $options = Currency::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Currency $currency) => [
                $currency->code => $currency->code.' - '.$currency->symbol,
            ])
            ->all();

        $current = strtoupper(trim((string) $currentCode));

        if ($current === '' || array_key_exists($current, $options)) {
            return $options;
        }

        $registered = Currency::query()->where('code', $current)->first();

        $options[$current] = $registered
            ? $registered->code.' - '.$registered->symbol.' (nonaktif)'
            : $current.' (belum terdaftar di modul Mata Uang)';

        return $options;
    }
}
