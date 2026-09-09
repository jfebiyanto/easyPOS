<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\CurrencyDenomination;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CurrencyController extends Controller
{
    public function index(): View
    {
        return view('admin.currencies.index', [
            'currencies' => apply_sort(
                Currency::withCount('denominations'),
                request('sort'), request('dir'),
                ['code', 'symbol', 'has_rounding', 'decimal_places', 'denominations_count', 'is_active', 'created_at'],
                'code', 'asc'
            )->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('admin.currencies.form', ['currency' => new Currency()]);
    }

    public function store(Request $request)
    {
        $currency = Currency::create($this->validated($request) + [
            'has_rounding' => $request->boolean('has_rounding'),
            'is_active' => $request->boolean('is_active'),
        ]);

        ActivityLogger::log('currency.created', "Membuat mata uang: {$currency->code} ({$currency->symbol})", $currency);

        return redirect()->route('admin.currencies.index')->with('success', 'Mata uang berhasil dibuat.');
    }

    // show() = halaman nominal pecahan (mengikuti pola price list items).
    public function show(Currency $currency): View
    {
        return view('admin.currencies.show', [
            'currency' => $currency->load(['denominations' => fn ($q) => $q->orderBy('value')]),
        ]);
    }

    public function edit(Currency $currency): View
    {
        return view('admin.currencies.form', ['currency' => $currency]);
    }

    public function update(Request $request, Currency $currency)
    {
        $currency->update($this->validated($request, $currency->id) + [
            'has_rounding' => $request->boolean('has_rounding'),
            'is_active' => $request->boolean('is_active'),
        ]);

        ActivityLogger::log('currency.updated', "Mengubah mata uang: {$currency->code} ({$currency->symbol})", $currency);

        return redirect()->route('admin.currencies.index')->with('success', 'Mata uang berhasil diperbarui.');
    }

    public function storeDenomination(Request $request, Currency $currency)
    {
        $data = $request->validate([
            'value' => [
                'required', 'numeric', 'min:0.01',
                Rule::unique('currency_denominations', 'value')->where('currency_id', $currency->id),
            ],
        ]);

        $denomination = $currency->denominations()->create([
            'value' => $data['value'],
            'is_active' => $request->boolean('is_active'),
        ]);

        ActivityLogger::log('currency.denomination_changed', 'Menambah pecahan '.$currency->symbol.' '.number_format($data['value'], $currency->decimal_places, ',', '.')." untuk {$currency->code}", $denomination, [
            'currency_id' => $currency->id, 'value' => $data['value'],
        ]);

        return redirect()->route('admin.currencies.show', $currency)->with('success', 'Nominal pecahan berhasil ditambahkan.');
    }

    public function destroyDenomination(Currency $currency, CurrencyDenomination $denomination)
    {
        $label = "Pecahan {$currency->symbol} {$denomination->value} dari mata uang {$currency->code}";
        $denomination->delete();

        ActivityLogger::log('currency.denomination_deleted', "Menghapus {$label}", $denomination, ['currency_id' => $currency->id]);

        return redirect()->route('admin.currencies.show', $currency)->with('success', 'Nominal pecahan berhasil dihapus.');
    }

    public function destroy(Currency $currency)
    {
        $label = $currency->code.' ('.$currency->symbol.')';
        $currency->delete(); // pecahan ikut terhapus (cascadeOnDelete)

        ActivityLogger::log('currency.deleted', "Menghapus mata uang: {$label}", $currency);

        return redirect()->route('admin.currencies.index')->with('success', 'Mata uang berhasil dihapus.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'alpha', Rule::unique('currencies', 'code')->ignore($id)],
            'symbol' => ['required', 'string', 'max:10'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:4'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Kode ISO 4217 selalu disimpan huruf besar.
        $data['code'] = strtoupper($data['code']);

        return $data;
    }
}
