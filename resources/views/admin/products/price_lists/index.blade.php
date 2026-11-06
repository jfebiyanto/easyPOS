@extends('layouts.admin')

@section('title', 'Price Lists')
@section('page_title', 'Price Lists')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Price Lists</h2>
    @if (auth()->user()->isSuperuser())
        <a href="{{ route('admin.price-lists.create') }}" class="btn btn-filled">
            <span class="material-symbols-rounded">add</span> Add Price List
        </a>
    @endif
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>{!! sort_link('Kode', 'code') !!}</th><th>{!! sort_link('Nama', 'name') !!}</th><th>Default</th><th>Period</th><th>{!! sort_link('Item Harga', 'items_count') !!}</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($priceLists as $priceList)
                <tr>
                    <td><strong>{{ $priceList->code }}</strong></td>
                    <td>{{ $priceList->name }}</td>
                    <td>@if ($priceList->is_default)<span class="badge info">Default</span>@else - @endif</td>
                    <td>{{ $priceList->effective_from?->format('d/m/Y') ?? '-' }} ~ {{ $priceList->effective_until?->format('d/m/Y') ?? '∞' }}</td>
                    <td>{{ $priceList->items_count }}</td>
                    <td><span class="badge {{ $priceList->is_active ? 'active' : 'inactive' }}">{{ $priceList->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            <a href="{{ route('admin.price-lists.show', $priceList) }}" class="btn btn-tonal btn-sm">Price Items</a>
                            @if (auth()->user()->isSuperuser())
                                <a href="{{ route('admin.price-lists.edit', $priceList) }}" class="btn btn-tonal btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.price-lists.destroy', $priceList) }}" onsubmit="return confirm('Hapus price list ini?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">sell</span><p>No price lists yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $priceLists])
@endsection
