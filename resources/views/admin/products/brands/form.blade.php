@extends('layouts.admin')

@section('title', $brand->exists ? 'Edit Brand' : 'Tambah Brand')
@section('page_title', $brand->exists ? 'Edit Brand' : 'Tambah Brand')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $brand->exists ? 'Ubah Brand' : 'Brand Baru' }}</h2>
    <p class="card-subtitle">Merek/brand produk.</p>

    <form method="POST" action="{{ $brand->exists ? route('admin.brands.update', $brand) : route('admin.brands.store') }}">
        @csrf
        @if ($brand->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $brand->code) }}" required maxlength="30">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama Brand *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $brand->name) }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $brand->exists ? $brand->is_active : true))>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.brands.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection