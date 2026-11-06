@extends('layouts.admin')

@section('title', 'Brands')
@section('page_title', 'Brands')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Brand List</h2>
    @unless ($readOnly)
    <a href="{{ route('admin.brands.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Add Brand
    </a>
    @endunless
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>{!! sort_link('Kode', 'code') !!}</th><th>{!! sort_link('Nama', 'name') !!}</th><th>{!! sort_link('Jumlah Produk', 'products_count') !!}</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($brands as $brand)
                <tr>
                    <td><strong>{{ $brand->code }}</strong></td>
                    <td>{{ $brand->name }}</td>
                    <td>{{ $brand->products_count }}</td>
                    <td><span class="badge {{ $brand->is_active ? 'active' : 'inactive' }}">{{ $brand->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @unless ($readOnly)
                            <a href="{{ route('admin.brands.edit', $brand) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.brands.destroy', $brand) }}" onsubmit="return confirm('Hapus brand ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-rounded">verified</span><p>No brands yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $brands])
@endsection