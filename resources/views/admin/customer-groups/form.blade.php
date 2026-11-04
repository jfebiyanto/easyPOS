@extends('layouts.admin')

@section('title', $group->exists ? 'Edit Grup Pelanggan' : 'Tambah Grup Pelanggan')
@section('page_title', $group->exists ? 'Edit Grup Pelanggan' : 'Tambah Grup Pelanggan')

@php $fromSap = $group->isFromSap(); @endphp

@section('content')
<div class="card">
    <h2 class="card-title">{{ $group->exists ? 'Ubah Grup Pelanggan' : 'Grup Pelanggan Baru' }}</h2>
    <p class="card-subtitle">Grup default menjadi pilihan awal saat membuat pelanggan baru, baik dari admin maupun POS.</p>

    @if ($fromSap)
        <div class="md-snackbar info">
            <span class="material-symbols-rounded">lock</span>
            Grup dari SAP B1: kode, nama, dan status hanya dapat diubah di SAP B1. Penanda default tetap dapat diatur di easyPOS.
        </div>
    @endif

    <form method="POST" action="{{ $group->exists ? route('admin.customer-groups.update', $group) : route('admin.customer-groups.store') }}">
        @csrf
        @if ($group->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $group->code) }}" required maxlength="30" @readonly($fromSap)>
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $group->name) }}" required maxlength="150" @readonly($fromSap)>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $group->is_active)) @disabled($fromSap)>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_default" name="is_default" value="1" @checked(old('is_default', $group->is_default))>
                    <label for="is_default">Default untuk pelanggan baru</label>
                </div>
                <div class="hint">Hanya satu grup yang dapat menjadi default; grup default sebelumnya otomatis dilepas.</div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.customer-groups.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection
