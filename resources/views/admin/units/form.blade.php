@extends('layouts.admin')

@section('title', $unit->exists ? 'Edit Satuan' : 'Tambah Satuan')
@section('page_title', $unit->exists ? 'Edit Satuan' : 'Tambah Satuan')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $unit->exists ? 'Ubah Satuan' : 'Satuan Baru' }}</h2>
    <p class="card-subtitle">Satuan dipakai produk, mis. PCS, KG, BOX.</p>

    <form method="POST" action="{{ $unit->exists ? route('admin.units.update', $unit) : route('admin.units.store') }}">
        @csrf
        @if ($unit->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $unit->code) }}" required maxlength="20">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $unit->name) }}" required maxlength="100">
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $unit->exists ? $unit->is_active : true))>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.units.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection
