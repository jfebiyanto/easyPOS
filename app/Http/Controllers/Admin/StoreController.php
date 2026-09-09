<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\Currency;
use App\Models\Store;
use App\Models\User;
use App\Rules\ValidStoreCurrency;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StoreController extends Controller
{
    use AuthorizesSuperuser;

    public function index(): View
    {
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
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperuser($request);

        $company = CompanyDetail::current();

        $data = $this->validated($request);

        // Default pajak mengikuti konfigurasi di level Company Details:
        // jika PPN aktif di company, store baru default aktif dan tarifnya mengikuti.
        $data['ppn_enabled'] = $company->ppn_enabled ? $request->boolean('ppn_enabled', $company->ppn_enabled) : $request->boolean('ppn_enabled');
        $data['ppn_rate'] = $data['ppn_enabled']
            ? ($request->filled('ppn_rate') ? $data['ppn_rate'] : $company->ppn_rate)
            : 0;

        $store = Store::create($data);

        // First store created becomes the active context automatically.
        if (! $request->session()->has('active_store_id')) {
            $request->session()->put('active_store_id', $store->id);
        }

        ActivityLogger::log('store.created', "Membuat store baru: {$store->code} · {$store->name}", $store);

        return redirect()->route('admin.stores.index')->with('success', 'Store berhasil dibuat.');
    }

    public function edit(Store $store): View
    {
        $this->ensureSuperuser(request());

        return view('admin.stores.form', [
            'store' => $store,
            'company' => CompanyDetail::current(),
            'currencies' => $this->currencyOptions($store->currency),
        ]);
    }

    public function update(Request $request, Store $store)
    {
        $this->ensureSuperuser($request);

        $store->update($this->validated($request, $store->id));

        ActivityLogger::log('store.updated', "Mengubah store: {$store->code} · {$store->name}", $store);

        return redirect()->route('admin.stores.index')->with('success', 'Konfigurasi store berhasil diperbarui.');
    }

    public function destroy(Store $store)
    {
        $this->ensureSuperuser(request());
        if (User::where('store_id', $store->id)->exists()) {
            return back()->with('error', 'Store masih memiliki user, tidak dapat dihapus.');
        }

        if ($store->products()->exists() || $store->productGroups()->exists() || $store->brands()->exists()) {
            return back()->with('error', 'Store masih memiliki data produk, tidak dapat dihapus.');
        }

        $store->delete();

        if (session('active_store_id') === $store->id) {
            session()->put('active_store_id', Store::query()->orderBy('code')->value('id'));
        }

        ActivityLogger::log('store.deleted', "Menghapus store: {$store->code} · {$store->name}", $store);

        return redirect()->route('admin.stores.index')->with('success', 'Store berhasil dihapus.');
    }

    /**
     * Set which store the master-data context (products, price lists, ...) operates on.
     */
    public function switch(Request $request, Store $store)
    {
        $this->ensureSuperuser($request);

        $request->session()->put('active_store_id', $store->id);

        ActivityLogger::log('store.switched', "Mengganti store aktif menjadi: {$store->code} · {$store->name}", $store);

        return redirect()->intended(route('admin.stores.index'))
            ->with('success', 'Store aktif: '.$store->code.' · '.$store->name);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('stores', 'code')->ignore($id)],
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
        ]);

        // Kode mata uang konsisten huruf besar (mengikuti modul Mata Uang).
        $data['currency'] = strtoupper(trim($data['currency']));

        return $data;
    }

    /**
     * Opsi dropdown "Mata Uang" pada form gerai, bersumber dari modul Mata Uang.
     * Hanya mata uang aktif yang dapat dipilih; nilai lama milik gerai
     * (nonaktif / belum terdaftar) tetap ditampilkan agar form edit tidak
     * kehilangan nilai yang sudah tersimpan.
     */
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
