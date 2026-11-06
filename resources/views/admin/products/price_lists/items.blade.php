@extends('layouts.admin')

@section('title', 'Item Harga: '.$priceList->name)
@section('page_title', 'Item Harga · '.$priceList->name)

@section('content')
@if (auth()->user()->isSuperuser())
    <div class="card">
        <h2 class="card-title">Add / Update Price Item</h2>
        <p class="card-subtitle">Price list: <strong>{{ $priceList->code }} · {{ $priceList->name }}</strong></p>

        <form method="POST" action="{{ route('admin.price-lists.items.store', $priceList) }}">
            @csrf
            <div class="form-grid">
                <div class="field @error('product_id') has-error @enderror">
                    <label for="product_id">Product *</label>
                    <select id="product_id" name="product_id" required>
                        <option value="">- Select Product -</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>{{ $product->sku }} · {{ $product->name }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('price') has-error @enderror">
                    <label for="price">Price (Rp) *</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" required value="{{ old('price') }}">
                    @error('price') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field @error('min_qty') has-error @enderror">
                    <label for="min_qty">Min. Qty *</label>
                    <input type="number" id="min_qty" name="min_qty" min="1" value="{{ old('min_qty', 1) }}" required>
                    @error('min_qty') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save Item</button>
            </div>
        </form>
    </div>
@else
    <div class="md-snackbar error">
        <span class="material-symbols-rounded">lock</span>
        You are a non-superuser Admin: price items are read-only.
    </div>
@endif

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>SKU</th><th>Product</th><th>Price</th><th>Min. Qty</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($priceList->items as $item)
                <tr>
                    <td><strong>{{ $item->product->sku ?? '-' }}</strong></td>
                    <td>{{ $item->product->name ?? '-' }}</td>
                    <td>Rp {{ number_format($item->price, 0, ',', '.') }}</td>
                    <td>{{ $item->min_qty }}</td>
                    <td>
                        <div class="actions-cell">
                            @if (auth()->user()->isSuperuser())
                                <form method="POST" action="{{ route('admin.price-lists.items.destroy', [$priceList, $item]) }}" onsubmit="return confirm('Hapus item ini?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-rounded">payments</span><p>No price items yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="form-actions" style="margin-top: 16px;">
    <a href="{{ route('admin.price-lists.index') }}" class="btn btn-outline">Back</a>
</div>
@endsection