@extends('layouts.admin')

@section('title', $currency->exists ? 'Edit Mata Uang' : 'Tambah Mata Uang')
@section('page_title', $currency->exists ? 'Edit Mata Uang' : 'Tambah Mata Uang')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $currency->exists ? 'Ubah Mata Uang' : 'Mata Uang Baru' }}</h2>
    <p class="card-subtitle">Mata uang yang dipakai di gerai. Kode mengikuti standar ISO 4217, contoh: IDR, USD, SGD.</p>

    <form method="POST" action="{{ $currency->exists ? route('admin.currencies.update', $currency) : route('admin.currencies.store') }}">
        @csrf
        @if ($currency->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Kode Mata Uang (ISO) *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $currency->code) }}" required maxlength="3" style="text-transform: uppercase;" placeholder="IDR">
                @error('code') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Kode ISO 4217, 3 huruf — contoh: IDR, USD, SGD.</div>
            </div>
            <div class="field @error('symbol') has-error @enderror">
                <label for="symbol">Simbol *</label>
                <input type="text" id="symbol" name="symbol" value="{{ old('symbol', $currency->symbol) }}" required maxlength="10" placeholder="Rp">
                @error('symbol') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('decimal_places') has-error @enderror">
                <label for="decimal_places">Angka di Belakang Koma *</label>
                <input type="number" id="decimal_places" name="decimal_places" min="0" max="4" step="1" required value="{{ old('decimal_places', $currency->decimal_places ?? 2) }}">
                @error('decimal_places') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Default 2 digit di belakang koma.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="has_rounding" name="has_rounding" value="1" @checked(old('has_rounding', $currency->exists ? $currency->has_rounding : false))>
                    <label for="has_rounding">Rounding (pembulatan)</label>
                </div>
                <div class="hint">Aktifkan bila nominal transaksi perlu dibulatkan.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $currency->exists ? $currency->is_active : true))>
                    <label for="is_active">Aktif</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan</button>
            <a href="{{ route('admin.currencies.index') }}" class="btn btn-outline">Batal</a>
        </div>
    </form>
</div>
@endsection
