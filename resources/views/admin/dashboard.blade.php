@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page_title', 'Dashboard')

@section('content')
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">store</span></div>
        <div><div class="stat-value">{{ $storeCount }}</div><div class="stat-label">Total Store</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">group</span></div>
        <div><div class="stat-value">{{ $userCount }}</div><div class="stat-label">User (store aktif)</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">inventory_2</span></div>
        <div><div class="stat-value">{{ $productCount }}</div><div class="stat-label">Total Produk</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">category</span></div>
        <div><div class="stat-value">{{ $groupCount }}</div><div class="stat-label">Kategori Produk</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">verified</span></div>
        <div><div class="stat-value">{{ $brandCount }}</div><div class="stat-label">Brand</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">percent</span></div>
        <div><div class="stat-value">{{ $promoCount }}</div><div class="stat-label">Promosi Aktif</div></div>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Informasi Store Aktif</h2>
    <p class="card-subtitle">Store yang menjadi konteks data master saat ini</p>
    @if ($store)
        <div class="form-grid">
            <div class="field">
                <label>Kode Store</label>
                <input type="text" value="{{ $store->code }}" disabled>
            </div>
            <div class="field">
                <label>Nama Store</label>
                <input type="text" value="{{ $store->name }}" disabled>
            </div>
            <div class="field">
                <label>Store Manager</label>
                <input type="text" value="{{ $store->pic_name }}" disabled>
            </div>
            <div class="field">
                <label>PPN</label>
                <input type="text" value="{{ $store->ppn_enabled ? $store->ppn_rate.'%' : 'Tidak aktif' }}" disabled>
            </div>
        </div>
        <div class="form-actions">
            <a href="{{ route('admin.stores.edit', $store) }}" class="btn btn-tonal">
                <span class="material-symbols-rounded">edit</span> Edit Store Ini
            </a>
            <a href="{{ route('admin.stores.index') }}" class="btn btn-outline">Ganti Store Aktif</a>
        </div>
    @else
        <div class="empty-state">
            <span class="material-symbols-rounded">store</span>
            <p>Belum ada konfigurasi store. <a href="{{ route('admin.stores.create') }}">Konfigurasi store sekarang</a>.</p>
        </div>
    @endif
</div>
@endsection