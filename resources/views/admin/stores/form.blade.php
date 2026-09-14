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
                    @forelse ($currencies as $code => $label)
                        <option value="{{ $code }}" @selected(old('currency', $store->currency ?? array_key_first($currencies)) === $code)>{{ $label }}</option>
                    @empty
                        <option value="">Belum ada mata uang terdaftar</option>
                    @endforelse
                </select>
                @error('currency') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Sumber pilihan: <a href="{{ route('admin.currencies.index') }}">modul Mata Uang</a>.</div>
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
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $store->exists ? $store->is_active : true))>
                    <label for="is_active">Store aktif</label>
                </div>
            </div>
            <div class="field" style="grid-column: 1 / -1;">
                <label>Pricelist yang dipakai gerai ini</label>
                <div class="checkbox-grid">
                    @forelse ($priceLists as $pl)
                        <div class="checkbox-row">
                            <input type="checkbox" id="pl_{{ $pl->id }}" name="price_list_ids[]" value="{{ $pl->id }}" @checked(in_array($pl->id, old('price_list_ids', $assigned)))>
                            <label for="pl_{{ $pl->id }}">{{ $pl->code }} · {{ $pl->name }} <span class="hint">{{ $pl->store_id ? '(store)' : '(company)' }}</span></label>
                        </div>
                    @empty
                        <div class="hint">Belum ada pricelist. Buat di <a href="{{ route('admin.price-lists.index') }}">Daftar Harga</a>.</div>
                    @endforelse
                </div>
                @if ($priceLists->isNotEmpty())
                    <div style="margin-top:10px; max-width:360px;">
                        <label for="default_price_list_id" style="display:block; font-size:13px; font-weight:500; color:var(--md-on-surface-variant); margin-bottom:6px;">Pricelist default</label>
                        <select id="default_price_list_id" name="default_price_list_id" style="width:100%; padding:12px 14px; border:1px solid var(--md-outline); border-radius:var(--md-radius-sm); background:var(--md-surface-container-high);">
                            <option value="">- Tidak ada -</option>
                            @foreach ($priceLists as $pl)
                                <option value="{{ $pl->id }}" @selected(old('default_price_list_id', $defaultPriceListId) == $pl->id)>{{ $pl->code }} · {{ $pl->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
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