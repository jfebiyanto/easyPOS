@extends('layouts.admin')

@section('title', 'Satuan')
@section('page_title', 'Satuan')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Daftar Satuan</h2>
    @unless ($readOnly)
    <a href="{{ route('admin.units.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Tambah Satuan
    </a>
    @endunless
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>{!! sort_link('Kode', 'code') !!}</th>
                <th>{!! sort_link('Nama', 'name') !!}</th>
                <th>{!! sort_link('Jumlah Produk', 'products_count') !!}</th>
                <th>{!! sort_link('Status', 'is_active') !!}</th>
                <th style="text-align:right;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($units as $unit)
                <tr>
                    <td><strong class="mono">{{ $unit->code }}</strong></td>
                    <td>{{ $unit->name }}</td>
                    <td>{{ $unit->products_count }}</td>
                    <td><span class="badge {{ $unit->is_active ? 'active' : 'inactive' }}">{{ $unit->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @unless ($readOnly)
                            <a href="{{ route('admin.units.edit', $unit) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.units.destroy', $unit) }}" onsubmit="return confirm('Hapus satuan {{ $unit->code }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-rounded">straighten</span><p>Belum ada satuan.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $units])
@endsection
