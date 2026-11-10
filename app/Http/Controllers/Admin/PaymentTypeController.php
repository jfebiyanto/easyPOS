<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesSuperuser;
use App\Http\Controllers\Concerns\AuthorizesCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyDetail;
use App\Models\CreditCard;
use App\Models\PaymentType;
use App\Models\Store;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentTypeController extends Controller
{
    use AuthorizesCompany, AuthorizesSuperuser;

    public function index(): View
    {
        return view('admin.payment-types.index', [
            'paymentTypes' => apply_sort(
                PaymentType::with(['stores:id,code,name', 'creditCard:id,code,name']),
                request('sort'), request('dir'),
                ['code', 'kind', 'order_number', 'count_type', 'is_active', 'created_at'],
                'order_number', 'asc'
            )->paginate(10),
        ]);
    }

    public function create(): View
    {
        $this->ensureSuperuser(request());

        return view('admin.payment-types.form', [
            'paymentType' => new PaymentType,
            'creditCards' => CreditCard::forCompany()->orderBy('code')->get(),
            'stores' => Store::where('company_id', current_company_id())->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperuser($request);

        $data = $this->validated($request);
        $isQrPayment = $request->boolean('is_qr_payment');
        $data['qr_image_path'] = $isQrPayment ? $request->file('qr_image')?->store('payment-qr', 'public') : null;
        unset($data['qr_image'], $data['remove_qr_image']);

        $paymentType = PaymentType::create($data + [
            'company_id' => current_company_id(),
            'is_qr_payment' => $isQrPayment,
            'is_active' => $request->boolean('is_active'),
        ]);
        $paymentType->stores()->sync($request->input('store_ids'));

        ActivityLogger::log('payment_type.created', "Membuat tipe pembayaran: {$paymentType->code}", $paymentType);

        return redirect()->route('admin.payment-types.index')->with('success', 'Payment method created successfully.');
    }

    public function edit(PaymentType $paymentType): View
    {
        $this->ensureSuperuser(request());
        $this->ensureCompany($paymentType->company_id);

        return view('admin.payment-types.form', [
            'paymentType' => $paymentType->load('stores:id,code,name'),
            'creditCards' => CreditCard::forCompany()->orderBy('code')->get(),
            'stores' => Store::where('company_id', current_company_id())->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, PaymentType $paymentType)
    {
        $this->ensureSuperuser($request);
        $this->ensureCompany($paymentType->company_id);

        $data = $this->validated($request, $paymentType->id);
        $oldQrPath = $paymentType->qr_image_path;
        $isQrPayment = $request->boolean('is_qr_payment');
        $newQrPath = $isQrPayment ? $request->file('qr_image')?->store('payment-qr', 'public') : null;

        // Bukan pembayaran QR: gambar lama dibuang.
        if (! $isQrPayment || $request->boolean('remove_qr_image')) {
            $data['qr_image_path'] = null;
        }
        if ($newQrPath) {
            $data['qr_image_path'] = $newQrPath;
        }
        unset($data['qr_image'], $data['remove_qr_image']);

        $paymentType->update($data + [
            'is_qr_payment' => $isQrPayment,
            'is_active' => $request->boolean('is_active'),
        ]);

        if ($oldQrPath && $oldQrPath !== $paymentType->qr_image_path) {
            Storage::disk('public')->delete($oldQrPath);
        }
        $paymentType->stores()->sync($request->input('store_ids'));

        ActivityLogger::log('payment_type.updated', "Mengubah tipe pembayaran: {$paymentType->code}", $paymentType);

        return redirect()->route('admin.payment-types.index')->with('success', 'Payment method updated successfully.');
    }

    public function destroy(PaymentType $paymentType)
    {
        $this->ensureSuperuser(request());
        $this->ensureCompany($paymentType->company_id);

        $label = $paymentType->code;
        $qrPath = $paymentType->qr_image_path;
        $paymentType->delete();
        if ($qrPath) {
            Storage::disk('public')->delete($qrPath);
        }

        ActivityLogger::log('payment_type.deleted', "Menghapus tipe pembayaran: {$label}", $paymentType);

        return redirect()->route('admin.payment-types.index')->with('success', 'Payment method deleted successfully.');
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('payment_types', 'code')->where('company_id', current_company_id())->ignore($id)],
            'description' => ['nullable', 'string', 'max:500'],
            'order_number' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(array_keys(PaymentType::KINDS))],
            'count_type' => ['required', Rule::in(array_keys(PaymentType::COUNT_TYPES))],
            'credit_card_id' => [
                // Custom wajib dipetakan ke Credit Card SAP selama integrasi SAP aktif.
                Rule::requiredIf(fn () => $request->input('kind') === PaymentType::KIND_CUSTOM && CompanyDetail::current()->sapIntegrationActive()),
                'nullable', 'string', Rule::exists('credit_cards', 'id')->where('company_id', current_company_id()),
            ],
            'is_qr_payment' => ['sometimes', 'boolean'],
            'qr_image' => [
                // Pembayaran QR wajib punya gambar: unggahan baru, atau gambar lama yang tidak dihapus.
                Rule::requiredIf(fn () => $request->boolean('is_qr_payment')
                    && ($request->boolean('remove_qr_image') || ! ($id && PaymentType::whereKey($id)->whereNotNull('qr_image_path')->exists()))),
                'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048',
            ],
            'remove_qr_image' => ['sometimes', 'boolean'],
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['string', Rule::exists('stores', 'id')->where('company_id', current_company_id())],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        unset($data['store_ids']);

        // Cash tidak dipetakan ke Credit Card SAP.
        if ($data['kind'] === PaymentType::KIND_CASH) {
            $data['credit_card_id'] = null;
        }

        return $data;
    }
}
