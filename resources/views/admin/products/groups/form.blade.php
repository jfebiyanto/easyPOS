@extends('layouts.admin')

@section('title', $group->exists ? 'Edit Kategori Produk' : 'Tambah Kategori Produk')
@section('page_title', $group->exists ? 'Edit Kategori Produk' : 'Tambah Kategori Produk')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $group->exists ? 'Ubah Kategori Produk' : 'Kategori Produk Baru' }}</h2>
    <p class="card-subtitle">Kelompok/kategori produk, mis. Minuman, Makanan, Rokok.</p>

    <form method="POST" action="{{ $group->exists ? route('admin.groups.update', $group) : route('admin.groups.store') }}">
        @csrf
        @if ($group->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $group->code) }}" required maxlength="30">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama Group *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $group->name) }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('description') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="description">Deskripsi</label>
                <textarea id="description" name="description" rows="2">{{ old('description', $group->description) }}</textarea>
                @error('description') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $group->exists ? $group->is_active : true))>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.groups.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection