@extends('layouts.admin')

@section('title', $currency->exists ? 'Edit Currency' : 'Add Currency')
@section('page_title', $currency->exists ? 'Edit Currency' : 'Add Currency')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $currency->exists ? 'Edit Currency' : 'New Currency' }}</h2>
    <p class="card-subtitle">Currencies used by stores. Codes follow ISO 4217, e.g. IDR, USD, SGD.</p>

    <form method="POST" action="{{ $currency->exists ? route('admin.currencies.update', $currency) : route('admin.currencies.store') }}">
        @csrf
        @if ($currency->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Currency Code (ISO) *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $currency->code) }}" required maxlength="3" style="text-transform: uppercase;" placeholder="IDR">
                @error('code') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">ISO 4217 code, 3 letters — e.g. IDR, USD, SGD.</div>
            </div>
            <div class="field @error('symbol') has-error @enderror">
                <label for="symbol">Symbol *</label>
                <input type="text" id="symbol" name="symbol" value="{{ old('symbol', $currency->symbol) }}" required maxlength="10" placeholder="Rp">
                @error('symbol') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('decimal_places') has-error @enderror">
                <label for="decimal_places">Decimal Places *</label>
                <input type="number" id="decimal_places" name="decimal_places" min="0" max="4" step="1" required value="{{ old('decimal_places', $currency->decimal_places ?? 2) }}">
                @error('decimal_places') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Defaults to 2 decimal places.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="has_rounding" name="has_rounding" value="1" @checked(old('has_rounding', $currency->exists ? $currency->has_rounding : false))>
                    <label for="has_rounding">Rounding</label>
                </div>
                <div class="hint">Enable if transaction amounts need to be rounded.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $currency->exists ? $currency->is_active : true))>
                    <label for="is_active">Active</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save</button>
            <a href="{{ route('admin.currencies.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
