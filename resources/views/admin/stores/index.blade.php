@extends('layouts.admin')

@section('title', 'Stores')
@section('page_title', 'Daftar Gerai')

@section('content')
<div class="table-toolbar">
    <div>
        <h2 style="margin:0; font-size:16px;">Daftar Store</h2>
        <p style="margin:2px 0 0; font-size:13px; color:var(--md-on-surface-variant);">
            Data master (produk, price list, promosi) bekerja pada <strong>store aktif</strong> di bawah.
        </p>
    </div>
    @if (auth()->user()->isSuperuser())
        <a href="{{ route('admin.stores.create') }}" class="btn btn-filled">
            <span class="material-symbols-rounded">add_business</span> Tambah Store
        </a>
    @endif
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>{!! sort_link('Kode', 'code') !!}</th>
                <th>{!! sort_link('Nama Store', 'name') !!}</th>
                <th>{!! sort_link('Store Manager', 'pic_name') !!}</th>
                <th>{!! sort_link('PPN', 'ppn_enabled') !!}</th>
                <th>{!! sort_link('User', 'users_count') !!}</th>
                <th>Status Konteks</th>
                <th style="text-align:right;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stores as $store)
                <tr>
                    <td><strong>{{ $store->code }}</strong></td>
                    <td>
                        {{ $store->name }}<br>
                        <span style="font-size:12px; color:var(--md-on-surface-variant);">{{ \Illuminate\Support\Str::limit($store->address, 60) }}</span>
                    </td>
                    <td>{{ $store->pic_name }}<br><span style="font-size:12px; color:var(--md-on-surface-variant);">{{ $store->pic_phone }}</span></td>
                    <td>{{ $store->ppn_enabled ? $store->ppn_rate.'%' : '-' }}</td>
                    <td>{{ $store->users_count }}</td>
                    <td>
                        @if ($store->id === $activeStoreId)
                            <span class="badge active">Store Aktif</span>
                        @else
                            <span class="badge inactive">-</span>
                        @endif
                    </td>
                    <td>
                        <div class="actions-cell">
                            @if ($store->id !== $activeStoreId && auth()->user()->isSuperuser())
                                <a href="{{ route('admin.stores.switch', $store) }}" class="btn btn-tonal btn-sm">Jadikan Aktif</a>
                            @endif
                            @if (auth()->user()->isSuperuser())
                                <a href="{{ route('admin.stores.edit', $store) }}" class="btn btn-tonal btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.stores.destroy', $store) }}" onsubmit="return confirm('Hapus store {{ $store->code }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">store</span><p>Belum ada store. <a href="{{ route('admin.stores.create') }}">Tambah store pertama</a>.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $stores])
@endsection