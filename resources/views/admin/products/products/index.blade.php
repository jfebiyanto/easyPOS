@extends('layouts.admin')

@section('title', 'Daftar Produk')
@section('page_title', 'Daftar Produk')

@section('content')
<div class="table-toolbar">
    <form method="GET" action="{{ route('admin.products.index') }}" class="search-box">
        <div class="field" style="margin-bottom:0; display:flex; gap:8px;">
            <input type="text" name="q" placeholder="Cari nama, SKU, atau barcode..." value="{{ request('q') }}">
            <select name="group" style="max-width: 180px;" onchange="this.form.submit()">
                <option value="">Semua Group</option>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected(request('group') == $group->id)>{{ $group->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-tonal"><span class="material-symbols-rounded">search</span></button>
        </div>
    </form>
    <a href="{{ route('admin.products.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Tambah Produk
    </a>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>Gambar</th><th>{!! sort_link('SKU', 'sku') !!}</th><th>{!! sort_link('Nama', 'name') !!}</th><th>Group</th><th>Brand</th><th>Unit</th><th>{!! sort_link('Harga Dasar', 'base_price') !!}</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Aksi</th></tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                <tr>
                    <td>
                        @if ($product->image_url)
                            <img src="{{ $product->image_url }}" alt="{{ $product->name }}" style="width:44px; height:44px; object-fit:cover; border-radius:8px; border:1px solid var(--color-outline);">
                        @else
                            <span class="chip chip-neutral">-</span>
                        @endif
                    </td>
                    <td><strong>{{ $product->sku }}</strong></td>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->group->name ?? '-' }}</td>
                    <td>{{ $product->brand->name ?? '-' }}</td>
                    <td>{{ $product->unit?->code ?? '-' }}</td>
                    <td>Rp {{ number_format($product->base_price, 0, ',', '.') }}</td>
                    <td><span class="badge {{ $product->is_active ? 'active' : 'inactive' }}">{{ $product->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.products.destroy', $product) }}" onsubmit="return confirm('Hapus produk ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="empty-state"><span class="material-symbols-rounded">inventory_2</span><p>Belum ada produk.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $products])
@endsection