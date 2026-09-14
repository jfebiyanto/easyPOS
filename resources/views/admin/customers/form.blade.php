@extends('layouts.admin')

@section('title', $customer->exists ? 'Edit Pelanggan' : 'Tambah Pelanggan')
@section('page_title', $customer->exists ? 'Edit Pelanggan' : 'Tambah Pelanggan')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $customer->exists ? 'Ubah Pelanggan' : 'Pelanggan Baru' }}</h2>
    <p class="card-subtitle">Isi minimal salah satu: Nomor HP atau Kode Customer.</p>

    <form method="POST" action="{{ $customer->exists ? route('admin.customers.update', $customer) : route('admin.customers.store') }}">
        @csrf
        @if ($customer->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $customer->name) }}" required maxlength="150">
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('phone') has-error @enderror">
                <label for="phone">Nomor HP</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $customer->phone) }}" maxlength="30">
                @error('phone') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode Customer</label>
                <input type="text" id="code" name="code" value="{{ old('code', $customer->code) }}" maxlength="30" placeholder="otomatis bila kosong">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('email') has-error @enderror">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $customer->email) }}">
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('address') has-error @enderror" style="grid-column:1/-1;">
                <label for="address">Alamat</label>
                <textarea id="address" name="address" rows="2">{{ old('address', $customer->address) }}</textarea>
                @error('address') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_member" name="is_member" value="1" @checked(old('is_member', $customer->exists ? $customer->is_member : true))>
                    <label for="is_member">Member (dapat poin)</label>
                </div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $customer->exists ? $customer->is_active : true))>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.customers.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection
