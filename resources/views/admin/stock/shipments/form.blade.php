@extends('layouts.admin')

@php $editing = $shipment->exists; @endphp

@section('title', $editing ? 'Edit Shipment' : 'Create Shipment')
@section('page_title', $editing ? 'Edit Shipment' : 'Create Shipment')

@section('content')
<form method="POST" action="{{ $editing ? route('admin.stock-transfer-shipments.update', $shipment) : route('admin.stock-transfer-shipments.store') }}">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <div class="card">
        <h2 class="card-title">Shipment Information</h2>
        <div class="form-grid">
            <div class="field">
                <label>Source Store</label>
                <input type="text" value="{{ $fromStore?->code }} · {{ $fromStore?->name }}" disabled>
            </div>
            <div class="field @error('to_store_id') has-error @enderror">
                <label for="to_store_id">Destination Store *</label>
                <select id="to_store_id" name="to_store_id" required>
                    @foreach ($destinations as $s)
                        <option value="{{ $s->id }}" @selected(old('to_store_id', $shipment->to_store_id) === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
                    @endforeach
                </select>
                @error('to_store_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label for="note">Notes</label>
                <input type="text" id="note" name="note" value="{{ old('note', $shipment->note) }}" maxlength="500">
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Product Lines</h2>
        @error('lines') <div class="error">{{ $message }}</div> @enderror
        <div id="lines"></div>
        <button type="button" class="btn btn-tonal btn-sm" id="add-line" style="margin-top:10px;">
            <span class="material-symbols-rounded">add</span> Add Line
        </button>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save Draft</button>
            <a href="{{ route('admin.stock-transfer-shipments.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </div>
</form>

<template id="line-template">
    <div class="field" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:10px;" data-line>
        <div style="flex:2;">
            <label>Product</label>
            <select name="lines[__i__][product_id]" style="width:100%;" data-product>
                <option value="">- Select Product -</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }}</option>
                @endforeach
            </select>
        </div>
        <div style="flex:1;">
            <label>Qty</label>
            <input type="number" step="0.001" min="0" name="lines[__i__][qty]" style="width:100%;" placeholder="0" data-qty>
        </div>
        <button type="button" class="icon-btn" data-remove title="Remove line">
            <span class="material-symbols-rounded">delete</span>
        </button>
    </div>
</template>

@push('scripts')
<script>
(function () {
    var container = document.getElementById('lines');
    var tpl = document.getElementById('line-template');
    var counter = 0;
    var existing = @json(old('lines', $shipment->exists ? $shipment->lines->map(fn ($l) => ['product_id' => $l->product_id, 'qty' => (float) $l->qty])->values()->all() : []));

    function addLine(values) {
        var html = tpl.innerHTML.replace(/__i__/g, counter++);
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var node = wrap.firstElementChild;
        if (values) {
            node.querySelector('[data-product]').value = values.product_id || '';
            node.querySelector('[data-qty]').value = values.qty || '';
        }
        node.querySelector('[data-remove]').addEventListener('click', function () { node.remove(); });
        container.appendChild(node);
    }

    document.getElementById('add-line').addEventListener('click', function () { addLine(); });

    if (existing.length) {
        existing.forEach(addLine);
    } else {
        addLine();
    }
})();
</script>
@endpush
@endsection
