@extends('layouts.admin')

@section('title', 'Product Catalog')
@section('page_title', 'Product Catalog')

@section('content')
<div class="table-toolbar">
    <form method="GET" action="{{ route('admin.products.index') }}" class="search-box">
        <div class="field" style="margin-bottom:0; display:flex; gap:8px;">
            <input type="text" name="q" placeholder="Search name, SKU or barcode..." value="{{ request('q') }}">
            <select name="group" style="max-width: 180px;" onchange="this.form.submit()">
                <option value="">All Groups</option>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected(request('group') == $group->id)>{{ $group->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-tonal"><span class="material-symbols-rounded">search</span></button>
        </div>
    </form>
    @unless ($readOnly)
    <a href="{{ route('admin.products.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Add Product
    </a>
    @endunless
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>Image</th><th>{!! sort_link('SKU', 'sku') !!}</th><th>{!! sort_link('Name', 'name') !!}</th><th>Group</th><th>Brand</th><th>Unit</th><th>{!! sort_link('Base Price', 'base_price') !!}</th><th>{!! sort_link('Status', 'is_active') !!}</th><th style="text-align:right;">Actions</th></tr>
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
                    <td><span class="badge {{ $product->is_active ? 'active' : 'inactive' }}">{{ $product->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @unless ($readOnly)
                            <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.products.destroy', $product) }}" onsubmit="return confirm('Delete this product?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="empty-state"><span class="material-symbols-rounded">inventory_2</span><p>No products yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $products])
@endsection