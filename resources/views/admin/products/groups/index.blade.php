@extends('layouts.admin')

@section('title', 'Kategori Produk')
@section('page_title', 'Kategori Produk')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Daftar Kategori Produk</h2>
    @if (auth()->user()->isSuperuser())
        <a href="{{ route('admin.groups.create') }}" class="btn btn-filled">
            <span class="material-symbols-rounded">add</span> Tambah Kategori
        </a>
    @endif
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>{!! sort_link('Kode', 'code') !!}</th><th>{!! sort_link('Nama', 'name') !!}</th><th>Deskripsi</th><th>{!! sort_link('Jumlah Produk', 'products_count') !!}</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Aksi</th></tr>
        </thead>
        <tbody>
            @forelse ($groups as $group)
                <tr>
                    <td><strong>{{ $group->code }}</strong></td>
                    <td>{{ $group->name }}</td>
                    <td>{{ $group->description ?? '-' }}</td>
                    <td>{{ $group->products_count }}</td>
                    <td><span class="badge {{ $group->is_active ? 'active' : 'inactive' }}">{{ $group->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @if (auth()->user()->isSuperuser())
                                <a href="{{ route('admin.groups.edit', $group) }}" class="btn btn-tonal btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.groups.destroy', $group) }}" onsubmit="return confirm('Hapus group ini?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            @else
                                <span class="badge inactive">Read-only</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">category</span><p>Belum ada kategori produk.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $groups])
@endsection