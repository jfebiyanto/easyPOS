@extends('layouts.admin')

@section('title', 'Promosi')
@section('page_title', 'Promosi')

@section('content')
<div class="tabs">
    <a href="{{ route('admin.groups.index') }}">Kategori Produk</a>
    <a href="{{ route('admin.products.index') }}">Daftar Produk</a>
    <a href="{{ route('admin.price-lists.index') }}">Daftar Harga</a>
    <a href="{{ route('admin.promotions.index') }}" class="active">Promosi</a>
    <a href="{{ route('admin.brands.index') }}">Daftar Merk</a>
</div>

<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Daftar Promosi</h2>
    <a href="{{ route('admin.promotions.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Tambah Promosi
    </a>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>{!! sort_link('Kode', 'code') !!}</th><th>{!! sort_link('Nama', 'name') !!}</th><th>{!! sort_link('Tipe', 'type') !!}</th><th>{!! sort_link('Nilai', 'value') !!}</th><th>Periode</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Aksi</th></tr>
        </thead>
        <tbody>
            @forelse ($promotions as $promotion)
                <tr>
                    <td><strong>{{ $promotion->code }}</strong></td>
                    <td>{{ $promotion->name }}</td>
                    <td><span class="badge info">{{ \App\Models\Promotion::TYPES[$promotion->type] ?? $promotion->type }}</span></td>
                    <td>
                        @if (in_array($promotion->type, ['discount_percent']))
                            {{ rtrim(rtrim($promotion->value, '0'), '.') }}%
                        @else
                            Rp {{ number_format($promotion->value, 0, ',', '.') }}
                        @endif
                    </td>
                    <td>{{ $promotion->starts_at?->format('d/m/Y') ?? '-' }} ~ {{ $promotion->ends_at?->format('d/m/Y') ?? '∞' }}</td>
                    <td><span class="badge {{ $promotion->is_active ? 'active' : 'inactive' }}">{{ $promotion->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            <a href="{{ route('admin.promotions.edit', $promotion) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.promotions.destroy', $promotion) }}" onsubmit="return confirm('Hapus promosi ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">percent</span><p>Belum ada promosi.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $promotions])
@endsection