@extends('layouts.admin')

@section('title', 'Create Stock Adjustment')
@section('page_title', 'Create Stock Adjustment')

@section('content')
<form method="POST" action="{{ route('admin.stock-adjustments.store') }}">
    @csrf

    <div class="card">
        <h2 class="card-title">Adjustment Information</h2>
        <div class="form-grid">
            @if ($stores->isNotEmpty())
                <div class="field @error('store_id') has-error @enderror">
                    <label for="store_id">Store *</label>
                    <select id="store_id" name="store_id" required>
                        @foreach ($stores as $s)
                            <option value="{{ $s->id }}" @selected(old('store_id', $defaultStoreId) === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
                        @endforeach
                    </select>
                    @error('store_id') <div class="error">{{ $message }}</div> @enderror
                </div>
            @endif
            <div class="field @error('date') has-error @enderror">
                <label for="date">Date *</label>
                <input type="date" id="date" name="date" value="{{ old('date', now()->format('Y-m-d')) }}" required>
                @error('date') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('reason') has-error @enderror" style="grid-column:1/-1;">
                <label for="reason">Reason</label>
                <input type="text" id="reason" name="reason" value="{{ old('reason') }}" maxlength="500" placeholder="e.g. stock count, damaged goods">
                @error('reason') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Product Lines</h2>
        <p class="card-subtitle">Enter the physically counted quantity (quantity after). The system calculates the difference against the current balance.</p>

        <div id="lines"></div>
        <button type="button" class="btn btn-tonal btn-sm" id="add-line" style="margin-top:10px;">
            <span class="material-symbols-rounded">add</span> Add Line
        </button>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save Draft</button>
            <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </div>
</form>

<template id="line-template">
    <div class="field" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:10px;" data-line>
        <div style="flex:2;">
            <label>Product</label>
            <select name="lines[__i__][product_id]" style="width:100%;">
                <option value="">- Select Product -</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }}</option>
                @endforeach
            </select>
            <div class="hint" data-current>Current stock: -</div>
        </div>
        <div style="flex:1;">
            <label>Qty After <span class="hint" style="display:block;">Counted quantity, not the difference</span></label>
            <input type="number" step="0.001" name="lines[__i__][qty_after]" style="width:100%;" placeholder="0">
            <div class="hint" data-diff>&nbsp;</div>
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

    var balanceUrl = @json(route('admin.stock-adjustments.balance'));
    var storeSelect = document.getElementById('store_id');

    function fmt(n) {
        return Number(n).toLocaleString('id-ID', { maximumFractionDigits: 3 });
    }

    function addLine() {
        var html = tpl.innerHTML.replace(/__i__/g, counter++);
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var node = wrap.firstElementChild;
        var product = node.querySelector('select');
        var qty = node.querySelector('input[type=number]');
        var current = node.querySelector('[data-current]');
        var diff = node.querySelector('[data-diff]');
        var balance = null;

        function renderDiff() {
            if (balance === null || qty.value === '') { diff.innerHTML = '&nbsp;'; return; }
            var d = Math.round((parseFloat(qty.value) - balance) * 1000) / 1000;
            diff.textContent = d === 0 ? 'No difference (will be skipped)' : 'Difference: ' + (d > 0 ? '+' : '') + fmt(d);
        }

        function loadBalance() {
            balance = null;
            current.textContent = 'Current stock: -';
            renderDiff();
            if (!product.value) { return; }
            var params = new URLSearchParams({ product_id: product.value });
            if (storeSelect) { params.set('store_id', storeSelect.value); }
            fetch(balanceUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                .then(function (data) { balance = parseFloat(data.balance); current.textContent = 'Current stock: ' + fmt(balance); renderDiff(); })
                .catch(function () { current.textContent = 'Current stock: unavailable'; });
        }

        product.addEventListener('change', loadBalance);
        qty.addEventListener('input', renderDiff);
        if (storeSelect) { storeSelect.addEventListener('change', loadBalance); }
        node.querySelector('[data-remove]').addEventListener('click', function () { node.remove(); });
        container.appendChild(node);
    }

    document.getElementById('add-line').addEventListener('click', addLine);
    addLine();
})();
</script>
@endpush
@endsection
