@extends('layouts.admin')

@section('title', $paymentType->exists ? 'Edit Payment Method' : 'Add Payment Method')
@section('page_title', $paymentType->exists ? 'Edit Payment Method' : 'Add Payment Method')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $paymentType->exists ? 'Edit Payment Method' : 'New Payment Method' }}</h2>
    <p class="card-subtitle">Payment methods used by stores: cash, QRIS, debit/credit, vouchers, etc.</p>

    <form method="POST" enctype="multipart/form-data" action="{{ $paymentType->exists ? route('admin.payment-types.update', $paymentType) : route('admin.payment-types.store') }}">
        @csrf
        @if ($paymentType->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Payment Method Code *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $paymentType->code) }}" required maxlength="30">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('order_number') has-error @enderror">
                <label for="order_number">Display Order *</label>
                <input type="number" id="order_number" name="order_number" min="1" step="1" required value="{{ old('order_number', $paymentType->order_number ?? 1) }}">
                @error('order_number') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('kind') has-error @enderror">
                <label for="kind">Type *</label>
                <select id="kind" name="kind">
                    @foreach (\App\Models\PaymentType::KINDS as $value => $label)
                        <option value="{{ $value }}" @selected(old('kind', $paymentType->kind ?? 'custom') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('kind') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Cash: posted to SAP as cash, no SAP Credit Card mapping needed. Custom: must be mapped to a SAP Credit Card (when the SAP integration is active).</div>
            </div>
            <div class="field @error('count_type') has-error @enderror">
                <label for="count_type">Count Type *</label>
                <select id="count_type" name="count_type">
                    @foreach (\App\Models\PaymentType::COUNT_TYPES as $value => $label)
                        <option value="{{ $value }}" @selected(old('count_type', $paymentType->count_type ?? 'summary') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('count_type') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('credit_card_id') has-error @enderror">
                <label for="credit_card_id">SAP B1 Credit Card <span id="credit_card_required" hidden>*</span></label>
                <select id="credit_card_id" name="credit_card_id">
                    <option value="">— Not mapped —</option>
                    @foreach ($creditCards as $card)
                        <option value="{{ $card->id }}" @selected(old('credit_card_id', $paymentType->credit_card_id) === $card->id)>{{ $card->label() }}</option>
                    @endforeach
                </select>
                @error('credit_card_id') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Required for Custom types: the SAP Incoming Payment posting uses this card. Not used for Cash. The card list comes from SAP synchronization.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $paymentType->exists ? $paymentType->is_active : true))>
                    <label for="is_active">Active</label>
                </div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_qr_payment" name="is_qr_payment" value="1" @checked(old('is_qr_payment', $paymentType->is_qr_payment))>
                    <label for="is_qr_payment">QR Payment</label>
                </div>
                <div class="hint">Check to show a static QR image (e.g. your QRIS) in the POS payment dialog.</div>
            </div>
            <div class="field @error('qr_image') has-error @enderror" id="qr_image_field" style="grid-column: 1 / -1;" @if (! old('is_qr_payment', $paymentType->is_qr_payment)) hidden @endif>
                <label for="qr_image">Static QR Image *</label>
                @if ($paymentType->exists && $paymentType->qr_image_url)
                    <div style="display:flex; align-items:center; gap:14px; margin-bottom:8px;">
                        <img src="{{ $paymentType->qr_image_url }}" alt="QR {{ $paymentType->code }}" style="width:120px; height:120px; object-fit:contain; border-radius:10px; border:1px solid var(--color-outline);">
                        <label style="font-size:13px; display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="remove_qr_image" value="1"> Remove this QR image
                        </label>
                    </div>
                @endif
                <input type="file" id="qr_image" name="qr_image" accept="image/png,image/jpeg,image/webp">
                <div class="hint">Required for QR payments. JPG/PNG/WebP, max 2MB. When set, the POS payment dialog shows this QR (e.g. your static QRIS) so the customer can scan it to pay.</div>
                @error('qr_image') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('description') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="2">{{ old('description', $paymentType->description) }}</textarea>
                @error('description') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('store_ids') has-error @enderror" style="grid-column: 1 / -1;">
                <label>Used in Stores *</label>
                <div class="checkbox-grid">
                    @foreach ($stores as $store)
                        <div class="checkbox-row">
                            <input type="checkbox" id="store_{{ $store->id }}" name="store_ids[]" value="{{ $store->id }}"
                                @checked(in_array($store->id, old('store_ids', $paymentType->exists ? $paymentType->stores->pluck('id')->all() : [])))>
                            <label for="store_{{ $store->id }}">{{ $store->code }} · {{ $store->name }}</label>
                        </div>
                    @endforeach
                </div>
                @error('store_ids') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Select one or more stores where this payment method can be used.</div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save</button>
            <a href="{{ route('admin.payment-types.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@push('scripts')
<script>
    (function () {
        var kind = document.getElementById('kind');
        var card = document.getElementById('credit_card_id');
        var star = document.getElementById('credit_card_required');
        var sync = function () {
            var isCash = kind.value === 'cash';
            card.disabled = isCash;
            if (isCash) { card.value = ''; }
            star.hidden = isCash;
        };
        kind.addEventListener('change', sync);
        sync();

        var isQr = document.getElementById('is_qr_payment');
        var qrField = document.getElementById('qr_image_field');
        isQr.addEventListener('change', function () { qrField.hidden = !isQr.checked; });
    })();
</script>
@endpush
@endsection
