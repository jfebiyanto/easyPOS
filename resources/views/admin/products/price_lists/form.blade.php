@extends('layouts.admin')

@section('title', $priceList->exists ? 'Edit Daftar Harga' : 'Tambah Daftar Harga')
@section('page_title', $priceList->exists ? 'Edit Daftar Harga' : 'Tambah Daftar Harga')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $priceList->exists ? 'Ubah Daftar Harga' : 'Daftar Harga Baru' }}</h2>
    <p class="card-subtitle">Contoh: Harga Reguler, Harga Grosir, Harga Member.</p>

    <form method="POST" action="{{ $priceList->exists ? route('admin.price-lists.update', $priceList) : route('admin.price-lists.store') }}">
        @csrf
        @if ($priceList->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $priceList->code) }}" required maxlength="30">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $priceList->name) }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('effective_from') has-error @enderror">
                <label for="effective_from">Berlaku Dari</label>
                <input type="date" id="effective_from" name="effective_from" value="{{ old('effective_from', $priceList->effective_from?->format('Y-m-d')) }}">
                @error('effective_from') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('effective_until') has-error @enderror">
                <label for="effective_until">Berlaku Sampai</label>
                <input type="date" id="effective_until" name="effective_until" value="{{ old('effective_until', $priceList->effective_until?->format('Y-m-d')) }}">
                @error('effective_until') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_default" name="is_default" value="1" @checked(old('is_default', $priceList->is_default ?? false))>
                    <label for="is_default">Jadikan default</label>
                </div>
                <div class="hint">Price list default dipakai bila tidak ada yang spesifik.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $priceList->exists ? $priceList->is_active : true))>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.price-lists.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection