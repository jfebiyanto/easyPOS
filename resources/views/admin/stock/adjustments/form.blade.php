@extends('layouts.admin')

@section('title', 'Buat Penyesuaian Stok')
@section('page_title', 'Buat Penyesuaian Stok')

@section('content')
<form method="POST" action="{{ route('admin.stock-adjustments.store') }}">
    @csrf

    <div class="card">
        <h2 class="card-title">Informasi Penyesuaian</h2>
        <div class="form-grid">
            @if ($stores->isNotEmpty())
                <div class="field @error('store_id') has-error @enderror">
                    <label for="store_id">Gerai *</label>
                    <select id="store_id" name="store_id" required>
                        @foreach ($stores as $s)
                            <option value="{{ $s->id }}" @selected(old('store_id', $defaultStoreId) === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
                        @endforeach
                    </select>
                    @error('store_id') <div class="error">{{ $message }}</div> @enderror
                </div>
            @endif
            <div class="field @error('date') has-error @enderror">
                <label for="date">Tanggal *</label>
                <input type="date" id="date" name="date" value="{{ old('date', now()->format('Y-m-d')) }}" required>
                @error('date') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('reason') has-error @enderror" style="grid-column:1/-1;">
                <label for="reason">Alasan</label>
                <input type="text" id="reason" name="reason" value="{{ old('reason') }}" maxlength="500" placeholder="mis. stok opname, barang rusak">
                @error('reason') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Baris Produk</h2>
        <p class="card-subtitle">Masukkan qty hasil hitung fisik (qty setelah). Sistem menghitung selisih terhadap saldo saat ini.</p>

        <div id="lines"></div>
        <button type="button" class="btn btn-tonal btn-sm" id="add-line" style="margin-top:10px;">
            <span class="material-symbols-rounded">add</span> Tambah Baris
        </button>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan Draft</button>
            <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-outline">Batal</a>
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
            <label>Qty Setelah</label>
            <input type="number" step="0.001" name="lines[__i__][qty_after]" style="width:100%;" placeholder="0">
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
