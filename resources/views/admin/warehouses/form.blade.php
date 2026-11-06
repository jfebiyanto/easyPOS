@extends('layouts.admin')

@section('title', $warehouse->exists ? 'Edit Warehouse' : 'Add Warehouse')
@section('page_title', $warehouse->exists ? 'Edit Warehouse' : 'Add Warehouse')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $warehouse->exists ? 'Edit Warehouse' : 'New Warehouse' }}</h2>
    <p class="card-subtitle">Warehouses for stock tracking. Linked to stores from the Stores menu.</p>

    <form method="POST" action="{{ $warehouse->exists ? route('admin.warehouses.update', $warehouse) : route('admin.warehouses.store') }}">
        @csrf
        @if ($warehouse->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Code *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $warehouse->code) }}" required maxlength="30">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $warehouse->name) }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('profit_center') has-error @enderror">
                <label for="profit_center">Profit Center</label>
                <input type="text" id="profit_center" name="profit_center" value="{{ old('profit_center', $warehouse->profit_center) }}" maxlength="50">
                @error('profit_center') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label for="cash_account">Cash Account</label>
                <input type="text" id="cash_account" value="{{ $warehouse->cash_account ?? '-' }}" readonly>
                <div class="hint">Taken from SAP B1 (UDF U_SOL_CASH_ACC) for the store's cash Incoming Payment.</div>
            </div>
            <div class="field">
                <label for="qris_account">QRIS Account</label>
                <input type="text" id="qris_account" value="{{ $warehouse->qris_account ?? '-' }}" readonly>
                <div class="hint">Taken from SAP B1 (UDF U_SOL_QRIS_ACC) for QRIS (Credit Card) payments on the Incoming Payment.</div>
            </div>
            <div class="field">
                <label for="edc_account">EDC Account</label>
                <input type="text" id="edc_account" value="{{ $warehouse->edc_account ?? '-' }}" readonly>
                <div class="hint">Taken from SAP B1 (UDF U_SOL_EDC_ACC) for EDC (Credit Card) payments on the Incoming Payment.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_retail" name="is_retail" value="1" @checked(old('is_retail', $warehouse->exists ? $warehouse->is_retail : true))>
                    <label for="is_retail">Retail warehouse</label>
                </div>
                <div class="hint">Only retail warehouses can be selected as a store warehouse.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $warehouse->exists ? $warehouse->is_active : true))>
                    <label for="is_active">Active</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save</button>
            <a href="{{ route('admin.warehouses.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
