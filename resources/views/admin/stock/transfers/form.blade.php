@extends('layouts.admin')

@section('title', 'Buat Transfer Stok')
@section('page_title', 'Buat Transfer Stok')

@section('content')
<form method="POST" action="{{ route('admin.stock-transfers.store') }}">
    @csrf

    <div class="card">
        <h2 class="card-title">Informasi Transfer</h2>
        <div class="form-grid">
            <div class="field @error('from_store_id') has-error @enderror">
                <label for="from_store_id">Gerai Asal *</label>
                <select id="from_store_id" name="from_store_id" required>
                    @foreach ($stores as $s)
                        <option value="{{ $s->id }}" @selected(old('from_store_id', $defaultFromStoreId) === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
                    @endforeach
                </select>
                @error('from_store_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('to_store_id') has-error @enderror">
                <label for="to_store_id">Gerai Tujuan *</label>
                <select id="to_store_id" name="to_store_id" required>
                    @foreach ($stores as $s)
                        <option value="{{ $s->id }}" @selected(old('to_store_id') === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
                    @endforeach
                </select>
                @error('to_store_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label for="note">Catatan</label>
                <input type="text" id="note" name="note" value="{{ old('note') }}" maxlength="500">
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Baris Produk</h2>
        <div id="lines"></div>
        <button type="button" class="btn btn-tonal btn-sm" id="add-line" style="margin-top:10px;">
            <span class="material-symbols-rounded">add</span> Tambah Baris
        </button>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan Draft</button>
            <a href="{{ route('admin.stock-transfers.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </div>
</form>

<template id="line-template">
    <div class="field" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:10px;" data-line>
        <div style="flex:2;">
            <label>Produk</label>
            <select name="lines[__i__][product_id]" style="width:100%;">
                <option value="">- Pilih Produk -</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }}</option>
                @endforeach
            </select>
        </div>
        <div style="flex:1;">
            <label>Qty</label>
            <input type="number" step="0.001" min="0" name="lines[__i__][qty]" style="width:100%;" placeholder="0">
        </div>
        <button type="button" class="icon-btn" data-remove title="Hapus baris">
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

    function addLine() {
        var html = tpl.innerHTML.replace(/__i__/g, counter++);
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var node = wrap.firstElementChild;
        node.querySelector('[data-remove]').addEventListener('click', function () { node.remove(); });
        container.appendChild(node);
    }

    document.getElementById('add-line').addEventListener('click', addLine);
    addLine();
})();
</script>
@endpush
@endsection
