@extends('layouts.admin')

@section('title', $product->exists ? 'Edit Produk' : 'Tambah Produk')
@section('page_title', $product->exists ? 'Edit Produk' : 'Tambah Produk')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $product->exists ? 'Ubah Produk' : 'Produk Baru' }}</h2>
    <p class="card-subtitle">Data master produk. Harga per price list dikelola di menu Daftar Harga.</p>

    <form method="POST" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}" enctype="multipart/form-data">
        @csrf
        @if ($product->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('image') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="image">Gambar Produk</label>
                @if ($product->exists && $product->image_url)
                    <div style="display:flex; align-items:center; gap:14px; margin-bottom:8px;">
                        <img src="{{ $product->image_url }}" alt="{{ $product->name }}" style="width:84px; height:84px; object-fit:cover; border-radius:10px; border:1px solid var(--color-outline);">
                        <label style="font-size:13px; display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="remove_image" value="1"> Hapus gambar ini
                        </label>
                    </div>
                @endif
                <input type="file" id="image" name="image" accept="image/png,image/jpeg,image/webp">
                <div class="hint">Format JPG/PNG/WebP, maks 2MB. Gambar ini tampil di halaman kasir.</div>
                @error('image') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('sku') has-error @enderror">
                <label for="sku">SKU *</label>
                <input type="text" id="sku" name="sku" value="{{ old('sku', $product->sku) }}" required maxlength="40" @readonly($product->exists)>
                @if ($product->exists)
                    <div class="hint">SKU tidak dapat diubah. Jika salah, nonaktifkan produk ini dan buat SKU baru.</div>
                @endif
                @error('sku') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('barcode') has-error @enderror">
                <label for="barcode">Barcode</label>
                <input type="text" id="barcode" name="barcode" value="{{ old('barcode', $product->barcode) }}" maxlength="60">
                @error('barcode') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Nama Produk *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $product->name) }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('product_group_id') has-error @enderror">
                <label for="product_group_id">Kategori Produk *</label>
                <select id="product_group_id" name="product_group_id" required>
                    <option value="">- Pilih Group -</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected(old('product_group_id', $product->product_group_id) == $group->id)>{{ $group->code }} · {{ $group->name }}</option>
                    @endforeach
                </select>
                @error('product_group_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('brand_id') has-error @enderror">
                <label for="brand_id">Brand</label>
                <select id="brand_id" name="brand_id">
                    <option value="">- Tanpa Brand -</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected(old('brand_id', $product->brand_id) == $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </select>
                @error('brand_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('unit_id') has-error @enderror">
                <label for="unit_id">Satuan *</label>
                <select id="unit_id" name="unit_id" required>
                    <option value="">- Pilih Satuan -</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected(old('unit_id', $product->unit_id) == $unit->id)>{{ $unit->code }} · {{ $unit->name }}</option>
                    @endforeach
                </select>
                @error('unit_id') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Kelola pilihan di <a href="{{ route('admin.units.index') }}">modul Satuan</a>.</div>
            </div>
            <div class="field @error('cost_price') has-error @enderror">
                <label for="cost_price">Harga Beli (Rp)</label>
                <input type="number" id="cost_price" name="cost_price" step="0.01" min="0" value="{{ old('cost_price', $product->cost_price ?? 0) }}">
                @error('cost_price') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('base_price') has-error @enderror">
                <label for="base_price">Harga Dasar / Jual (Rp) *</label>
                <input type="number" id="base_price" name="base_price" step="0.01" min="0" required value="{{ old('base_price', $product->base_price ?? 0) }}">
                @error('base_price') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('description') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="description">Deskripsi</label>
                <textarea id="description" name="description" rows="2">{{ old('description', $product->description) }}</textarea>
                @error('description') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="track_stock" name="track_stock" value="1" @checked(old('track_stock', $product->exists ? $product->track_stock : true))>
                    <label for="track_stock">Lacak stok</label>
                </div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $product->exists ? $product->is_active : true))>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.products.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection