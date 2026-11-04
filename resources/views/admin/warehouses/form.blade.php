@extends('layouts.admin')

@section('title', $warehouse->exists ? 'Edit Warehouse' : 'Tambah Warehouse')
@section('page_title', $warehouse->exists ? 'Edit Warehouse' : 'Tambah Warehouse')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $warehouse->exists ? 'Ubah Warehouse' : 'Warehouse Baru' }}</h2>
    <p class="card-subtitle">Gudang untuk pencatatan stok. Dihubungkan ke gerai dari menu Daftar Gerai.</p>

    <form method="POST" action="{{ $warehouse->exists ? route('admin.warehouses.update', $warehouse) : route('admin.warehouses.store') }}">
        @csrf
        @if ($warehouse->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $warehouse->code) }}" required maxlength="30">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $warehouse->name) }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('profit_center') has-error @enderror">
                <label for="profit_center">Profit Center</label>
                <input type="text" id="profit_center" name="profit_center" value="{{ old('profit_center', $warehouse->profit_center) }}" maxlength="50">
                @error('profit_center') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label for="cash_account">Akun Kas</label>
                <input type="text" id="cash_account" value="{{ $warehouse->cash_account ?? '-' }}" readonly>
                <div class="hint">Diambil dari SAP B1 (UDF U_SOL_CASH_ACC) untuk Incoming Payment tunai gerai.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_retail" name="is_retail" value="1" @checked(old('is_retail', $warehouse->exists ? $warehouse->is_retail : true))>
                    <label for="is_retail">Warehouse retail</label>
                </div>
                <div class="hint">Hanya warehouse retail yang dapat dipilih sebagai warehouse gerai.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $warehouse->exists ? $warehouse->is_active : true))>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.warehouses.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection
