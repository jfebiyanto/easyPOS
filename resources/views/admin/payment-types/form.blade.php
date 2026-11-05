@extends('layouts.admin')

@section('title', $paymentType->exists ? 'Edit Tipe Pembayaran' : 'Tambah Tipe Pembayaran')
@section('page_title', $paymentType->exists ? 'Edit Tipe Pembayaran' : 'Tambah Tipe Pembayaran')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $paymentType->exists ? 'Ubah Tipe Pembayaran' : 'Tipe Pembayaran Baru' }}</h2>
    <p class="card-subtitle">Konfigurasi metode pembayaran yang dipakai di gerai: tunai, QRIS, debit/kredit, voucher, dll.</p>

    <form method="POST" action="{{ $paymentType->exists ? route('admin.payment-types.update', $paymentType) : route('admin.payment-types.store') }}">
        @csrf
        @if ($paymentType->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode Tipe Pembayaran *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $paymentType->code) }}" required maxlength="30">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('order_number') has-error @enderror">
                <label for="order_number">Order Number *</label>
                <input type="number" id="order_number" name="order_number" min="1" step="1" required value="{{ old('order_number', $paymentType->order_number ?? 1) }}">
                @error('order_number') <div class="error">{{ $message }}</div> @enderror
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
                <label for="credit_card_id">Kartu Kredit SAP B1</label>
                <select id="credit_card_id" name="credit_card_id">
                    <option value="">— Tidak dipetakan —</option>
                    @foreach ($creditCards as $card)
                        <option value="{{ $card->id }}" @selected(old('credit_card_id', $paymentType->credit_card_id) === $card->id)>{{ $card->label() }}</option>
                    @endforeach
                </select>
                @error('credit_card_id') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Diisi untuk pembayaran non-tunai (QRIS, EDC/kartu): posting Incoming Payment SAP memakai kartu ini. Tipe berkode CASH dicatat sebagai tunai. Daftar kartu berasal dari sinkronisasi SAP.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $paymentType->exists ? $paymentType->is_active : true))>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
            <div class="field @error('description') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="description">Deskripsi</label>
                <textarea id="description" name="description" rows="2">{{ old('description', $paymentType->description) }}</textarea>
                @error('description') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('store_ids') has-error @enderror" style="grid-column: 1 / -1;">
                <label>Digunakan di Gerai *</label>
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
                <div class="hint">Pilih satu atau lebih gerai tempat tipe pembayaran ini dapat digunakan.</div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.payment-types.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection
