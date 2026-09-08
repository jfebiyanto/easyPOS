@extends('layouts.admin')

@section('title', $store->exists ? 'Edit Store' : 'Tambah Store')
@section('page_title', $store->exists ? 'Edit Store · '.$store->code : 'Tambah Store')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $store->exists ? 'Ubah Daftar Gerai' : 'Store Baru' }}</h2>
    <p class="card-subtitle">Pengaturan dasar store: identitas, store manager, dan PPN.</p>

    <form method="POST" action="{{ $store->exists ? route('admin.stores.update', $store) : route('admin.stores.store') }}">
        @csrf
        @if ($store->exists) @method('PUT') @endif
        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode Store *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $store->code ?? '') }}" required maxlength="20">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama Store *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $store->name ?? '') }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('address') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="address">Alamat Store</label>
                <textarea id="address" name="address" rows="2">{{ old('address', $store->address ?? '') }}</textarea>
                @error('address') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('pic_name') has-error @enderror">
                <label for="pic_name">Store Manager *</label>
                <input type="text" id="pic_name" name="pic_name" value="{{ old('pic_name', $store->pic_name ?? '') }}" required>
                @error('pic_name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('pic_phone') has-error @enderror">
                <label for="pic_phone">Telepon PJ</label>
                <input type="text" id="pic_phone" name="pic_phone" value="{{ old('pic_phone', $store->pic_phone ?? '') }}" maxlength="30">
                @error('pic_phone') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('currency') has-error @enderror">
                <label for="currency">Mata Uang *</label>
                <select id="currency" name="currency">
                    @foreach (['IDR' => 'IDR - Rupiah', 'USD' => 'USD - Dollar'] as $c => $label)
                        <option value="{{ $c }}" @selected(old('currency', $store->currency ?? 'IDR') === $c)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="ppn_enabled" name="ppn_enabled" value="1" @checked(old('ppn_enabled', $store->exists ? $store->ppn_enabled : ($company->ppn_enabled ?? false)))>
                    <label for="ppn_enabled">Aktifkan PPN</label>
                </div>
                <div class="hint">
                    Jika aktif, transaksi POS akan dikenakan PPN sesuai tarif di bawah.
                    @if (! $store->exists && ($company->ppn_enabled ?? false))
                        <strong>Default mengikuti perusahaan: PPN {{ $company->ppn_rate }}% aktif.</strong>
                    @endif
                </div>
            </div>
            <div class="field @error('ppn_rate') has-error @enderror">
                <label for="ppn_rate">Tarif PPN (%)</label>
                <input type="number" id="ppn_rate" name="ppn_rate" step="0.01" min="0" max="100" value="{{ old('ppn_rate', $store->exists ? $store->ppn_rate : ($company->ppn_rate ?? 11)) }}">
                @error('ppn_rate') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled">
                <span class="material-symbols-rounded">save</span> Simpan
            </button>
            <a href="{{ route('admin.stores.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection