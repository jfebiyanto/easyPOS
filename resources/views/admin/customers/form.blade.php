@extends('layouts.admin')

@section('title', $customer->exists ? 'Edit Pelanggan' : 'Tambah Pelanggan')
@section('page_title', $customer->exists ? 'Edit Pelanggan' : 'Tambah Pelanggan')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $customer->exists ? 'Ubah Pelanggan' : 'Pelanggan Baru' }}</h2>
    @php
        $fromSap = $customer->isFromSap();
        $syncLabels = ['pending' => 'Menunggu dikirim ke SAP B1', 'synced' => 'Tersinkron dengan SAP B1', 'failed' => 'Gagal dikirim ke SAP B1'];
    @endphp
    <p class="card-subtitle">{{ $sapActive ? 'Nomor HP wajib diisi; kode customer dibuat SAP B1.' : 'Isi minimal salah satu: Nomor HP atau Kode Customer.' }}</p>

    @if ($fromSap)
        <div class="md-snackbar info">
            <span class="material-symbols-rounded">lock</span>
            Customer dari SAP B1: data customer hanya dapat diubah di SAP B1. Status member tetap dapat diatur di easyPOS.
        </div>
    @elseif ($customer->exists && $customer->sap_sync_status)
        <div class="md-snackbar {{ $customer->sap_sync_status === 'failed' ? 'error' : 'info' }}">
            <span class="material-symbols-rounded">{{ $customer->sap_sync_status === 'synced' ? 'cloud_done' : ($customer->sap_sync_status === 'failed' ? 'cloud_off' : 'cloud_upload') }}</span>
            <span>{{ $syncLabels[$customer->sap_sync_status] ?? $customer->sap_sync_status }}@if ($customer->sap_sync_error): {{ $customer->sap_sync_error }}@endif</span>
        </div>
    @endif

    <form method="POST" action="{{ $customer->exists ? route('admin.customers.update', $customer) : route('admin.customers.store') }}">
        @csrf
        @if ($customer->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $customer->name) }}" required maxlength="150" @readonly($fromSap)>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('phone') has-error @enderror">
                <label for="phone">Nomor HP</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $customer->phone) }}" maxlength="30" @readonly($fromSap) @required($sapActive && ! $fromSap)>
                @error('phone') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode Customer</label>
                <input type="text" id="code" name="code" value="{{ old('code', $customer->code) }}" maxlength="30"
                       placeholder="{{ $sapActive ? 'dibuat SAP B1 (CardCode)' : 'otomatis bila kosong' }}" @readonly($sapActive || $fromSap)>
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('email') has-error @enderror">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $customer->email) }}" @readonly($fromSap)>
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('customer_group_id') has-error @enderror">
                <label for="customer_group_id">Customer Group</label>
                <select id="customer_group_id" name="customer_group_id" @disabled($fromSap)>
                    <option value="">- {{ $sapActive ? 'Default SAP B1' : 'Tanpa grup' }} -</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected(old('customer_group_id', $customer->customer_group_id) == $group->id)>{{ $group->code }} · {{ $group->name }}</option>
                    @endforeach
                </select>
                @error('customer_group_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('address') has-error @enderror" style="grid-column:1/-1;">
                <label for="address">Alamat</label>
                <textarea id="address" name="address" rows="2" @readonly($fromSap)>{{ old('address', $customer->address) }}</textarea>
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
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $customer->exists ? $customer->is_active : true)) @disabled($fromSap)>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.customers.index') }}" class="btn btn-outline">Batal</a>
            @if ($sapActive && $customer->exists && ! $fromSap && $customer->sap_sync_status === 'failed')
                <button type="submit" form="sap-sync-form" class="btn btn-outline"><span class="material-symbols-rounded">sync</span> Kirim Ulang ke SAP</button>
            @endif
        </div>
    </form>

    @if ($sapActive && $customer->exists && ! $fromSap)
        <form id="sap-sync-form" method="POST" action="{{ route('admin.customers.sap-sync', $customer) }}">@csrf</form>
    @endif
</div>
@endsection
