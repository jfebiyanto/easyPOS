@extends('layouts.admin')

@section('title', $promotion->exists ? 'Edit Promotion' : 'Add Promotion')
@section('page_title', $promotion->exists ? 'Edit Promotion' : 'Add Promotion')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $promotion->exists ? 'Edit Promotion' : 'New Promotion' }}</h2>
    <p class="card-subtitle">Promotions are applied in the POS according to their type and active period.</p>

    <form method="POST" action="{{ $promotion->exists ? route('admin.promotions.update', $promotion) : route('admin.promotions.store') }}">
        @csrf
        @if ($promotion->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Code *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $promotion->code) }}" required maxlength="30">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Promotion Name *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $promotion->name) }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('type') has-error @enderror">
                <label for="type">Type *</label>
                <select id="type" name="type" data-promo-type>
                    @foreach (\App\Models\Promotion::TYPES as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', $promotion->type ?? 'discount_amount') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('type') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('value') has-error @enderror" data-promo-types="discount_percent discount_amount special_price">
                <label for="value" data-promo-labels='{"discount_percent":"Diskon (%) *","discount_amount":"Potongan per Item (Rp) *","special_price":"Harga Khusus per Item (Rp) *"}'>Value *</label>
                <input type="number" id="value" name="value" step="0.01" min="0" required value="{{ old('value', $promotion->value ?? 0) }}">
                @error('value') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('product_id') has-error @enderror">
                <label for="product_id" data-promo-labels='{"discount_percent":"Product (optional)","discount_amount":"Product (optional)","special_price":"Product *","buy_x_get_y":"Purchased Product *"}'>Product</label>
                <select id="product_id" name="product_id">
                    <option value="">- All Products -</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(old('product_id', $promotion->product_id) == $product->id)>{{ $product->sku }} · {{ $product->name }}</option>
                    @endforeach
                </select>
                @error('product_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('min_qty') has-error @enderror" data-promo-types="discount_percent discount_amount special_price">
                <label for="min_qty">Min. Qty</label>
                <input type="number" id="min_qty" name="min_qty" min="1" value="{{ old('min_qty', $promotion->min_qty ?? 1) }}">
                @error('min_qty') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('buy_qty') has-error @enderror" data-promo-types="buy_x_get_y">
                <label for="buy_qty">Buy (X) *</label>
                <input type="number" id="buy_qty" name="buy_qty" min="1" required value="{{ old('buy_qty', $promotion->buy_qty) }}">
                <div class="hint">Number of products that must be purchased.</div>
                @error('buy_qty') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('get_qty') has-error @enderror" data-promo-types="buy_x_get_y">
                <label for="get_qty">Free (Y) *</label>
                <input type="number" id="get_qty" name="get_qty" min="1" required value="{{ old('get_qty', $promotion->get_qty) }}">
                <div class="hint">Bonus quantity for every X purchased.</div>
                @error('get_qty') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('get_product_id') has-error @enderror" data-promo-types="buy_x_get_y">
                <label for="get_product_id">Bonus Product</label>
                <select id="get_product_id" name="get_product_id">
                    <option value="">- Same as purchased product -</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(old('get_product_id', $promotion->get_product_id) == $product->id)>{{ $product->sku }} · {{ $product->name }}</option>
                    @endforeach
                </select>
                @error('get_product_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('max_applications') has-error @enderror" data-promo-types="buy_x_get_y">
                <label for="max_applications">Max. Multiples</label>
                <input type="number" id="max_applications" name="max_applications" min="1" value="{{ old('max_applications', $promotion->max_applications) }}">
                <div class="hint">Leave blank for unlimited multiples.</div>
                @error('max_applications') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('starts_at') has-error @enderror">
                <label for="starts_at">Started</label>
                <input type="datetime-local" id="starts_at" name="starts_at" value="{{ old('starts_at', $promotion->starts_at?->format('Y-m-d\TH:i')) }}">
                @error('starts_at') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('ends_at') has-error @enderror">
                <label for="ends_at">Ends</label>
                <input type="datetime-local" id="ends_at" name="ends_at" value="{{ old('ends_at', $promotion->ends_at?->format('Y-m-d\TH:i')) }}">
                @error('ends_at') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('description') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="2">{{ old('description', $promotion->description) }}</textarea>
                @error('description') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $promotion->exists ? $promotion->is_active : true))>
                    <label for="is_active">Active</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save</button>
            <a href="{{ route('admin.promotions.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection