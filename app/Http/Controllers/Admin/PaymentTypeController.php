<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentType;
use App\Models\Store;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentTypeController extends Controller
{
    public function index(): View
    {
        return view('admin.payment-types.index', [
            'paymentTypes' => apply_sort(
                PaymentType::with('stores:id,code,name'),
                request('sort'), request('dir'),
                ['code', 'order_number', 'count_type', 'is_active', 'created_at'],
                'order_number', 'asc'
            )->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('admin.payment-types.form', [
            'paymentType' => new PaymentType(),
            'stores' => Store::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $paymentType = PaymentType::create($this->validated($request) + [
            'is_active' => $request->boolean('is_active'),
        ]);
        // store_ids sudah tervalidasi; sinkronkan ke pivot gerai.
        $paymentType->stores()->sync($request->input('store_ids'));

        ActivityLogger::log('payment_type.created', "Membuat tipe pembayaran: {$paymentType->code}", $paymentType);

        return redirect()->route('admin.payment-types.index')->with('success', 'Tipe pembayaran berhasil dibuat.');
    }

    public function edit(PaymentType $paymentType): View
    {
        return view('admin.payment-types.form', [
            'paymentType' => $paymentType->load('stores:id,code,name'),
            'stores' => Store::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, PaymentType $paymentType)
    {
        $paymentType->update($this->validated($request, $paymentType->id) + [
            'is_active' => $request->boolean('is_active'),
        ]);
        $paymentType->stores()->sync($request->input('store_ids'));

        ActivityLogger::log('payment_type.updated', "Mengubah tipe pembayaran: {$paymentType->code}", $paymentType);

        return redirect()->route('admin.payment-types.index')->with('success', 'Tipe pembayaran berhasil diperbarui.');
    }

    public function destroy(PaymentType $paymentType)
    {
        $label = $paymentType->code;
        $paymentType->delete();

        ActivityLogger::log('payment_type.deleted', "Menghapus tipe pembayaran: {$label}", $paymentType);

        return redirect()->route('admin.payment-types.index')->with('success', 'Tipe pembayaran berhasil dihapus.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('payment_types', 'code')->ignore($id)],
            'description' => ['nullable', 'string', 'max:500'],
            'order_number' => ['required', 'integer', 'min:1'],
            'count_type' => ['required', Rule::in(array_keys(PaymentType::COUNT_TYPES))],
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['string', 'exists:stores,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // store_ids hanya untuk sinkronisasi pivot, bukan kolom payment_types.
        unset($data['store_ids']);

        return $data;
    }
}
