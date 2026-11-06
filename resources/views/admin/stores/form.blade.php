@extends('layouts.admin')

@section('title', $store->exists ? 'Edit Store' : 'Add Store')
@section('page_title', $store->exists ? 'Edit Store · '.$store->code : 'Add Store')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $store->exists ? 'Edit Store' : 'New Store' }}</h2>
    <p class="card-subtitle">Basic store settings: identity, store manager and VAT.</p>

    <form method="POST" action="{{ $store->exists ? route('admin.stores.update', $store) : route('admin.stores.store') }}">
        @csrf
        @if ($store->exists) @method('PUT') @endif
        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Store Code *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $store->code ?? '') }}" required maxlength="20">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Store Name *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $store->name ?? '') }}" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('address') has-error @enderror" style="grid-column: 1 / -1;">
                <label for="address">Store Address</label>
                <textarea id="address" name="address" rows="2">{{ old('address', $store->address ?? '') }}</textarea>
                @error('address') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('pic_name') has-error @enderror">
                <label for="pic_name">Store Manager *</label>
                <input type="text" id="pic_name" name="pic_name" value="{{ old('pic_name', $store->pic_name ?? '') }}" required>
                @error('pic_name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('pic_phone') has-error @enderror">
                <label for="pic_phone">Manager Phone</label>
                <input type="text" id="pic_phone" name="pic_phone" value="{{ old('pic_phone', $store->pic_phone ?? '') }}" maxlength="30">
                @error('pic_phone') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('currency') has-error @enderror">
                <label for="currency">Currency *</label>
                <select id="currency" name="currency">
                    @forelse ($currencies as $code => $label)
                        <option value="{{ $code }}" @selected(old('currency', $store->currency ?? array_key_first($currencies)) === $code)>{{ $label }}</option>
                    @empty
                        <option value="">No currencies registered yet</option>
                    @endforelse
                </select>
                @error('currency') <div class="error">{{ $message }}</div> @enderror
                <div class="hint">Options come from: <a href="{{ route('admin.currencies.index') }}">the Currencies module</a>.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="ppn_enabled" name="ppn_enabled" value="1" @checked(old('ppn_enabled', $store->exists ? $store->ppn_enabled : ($company->ppn_enabled ?? false)))>
                    <label for="ppn_enabled">Enable VAT</label>
                </div>
                <div class="hint">
                    When enabled, POS sales are charged VAT at the rate below.
                    @if (! $store->exists && ($company->ppn_enabled ?? false))
                        <strong>Default mengikuti perusahaan: PPN {{ $company->ppn_rate }}% aktif.</strong>
                    @endif
                </div>
            </div>
            <div class="field @error('ppn_rate') has-error @enderror">
                <label for="ppn_rate">VAT Rate (%)</label>
                <input type="number" id="ppn_rate" name="ppn_rate" step="0.01" min="0" max="100" value="{{ old('ppn_rate', $store->exists ? $store->ppn_rate : ($company->ppn_rate ?? 11)) }}">
                @error('ppn_rate') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $store->exists ? $store->is_active : true))>
                    <label for="is_active">Store active</label>
                </div>
            </div>
            <div class="field @error('warehouse_id') has-error @enderror">
                <label for="warehouse_id">Warehouse (stock tracking)</label>
                <select id="warehouse_id" name="warehouse_id">
                    <option value="">- Not linked -</option>
                    @foreach ($warehouses as $wh)
                        <option value="{{ $wh->id }}" @selected(old('warehouse_id', $store->warehouse_id) == $wh->id)>{{ $wh->code }} · {{ $wh->name }}</option>
                    @endforeach
                </select>
                @error('warehouse_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('default_customer_id') has-error @enderror">
                <label for="default_customer_id">Default Retail Customer</label>
                <select id="default_customer_id" name="default_customer_id">
                    <option value="">- None -</option>
                    @foreach ($customers as $cust)
                        <option value="{{ $cust->id }}" @selected(old('default_customer_id', $store->default_customer_id) == $cust->id)>{{ $cust->code }} · {{ $cust->name }}</option>
                    @endforeach
                </select>
                <div class="hint">For non-member walk-in sales. Not recorded as a member and earns no points.</div>
                @error('default_customer_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field" style="grid-column: 1 / -1;">
                @if ($priceLists->isEmpty())
                    <div class="hint">No price list yet. Create one in <a href="{{ route('admin.price-lists.index') }}">Price Lists</a>.</div>
                @else
                    <div style="display:flex; flex-wrap:wrap; gap:16px;">
                    @foreach (['sales_price_list_id' => ['Pricelist Jual', $salesPriceListId], 'purchase_price_list_id' => ['Pricelist Beli', $store->purchase_price_list_id]] as $field => [$label, $current])
                        <div style="flex:1 1 260px; max-width:360px;">
                            <label for="{{ $field }}" style="display:block; font-size:13px; font-weight:500; color:var(--md-on-surface-variant); margin-bottom:6px;">{{ $label }}</label>
                            <select id="{{ $field }}" name="{{ $field }}" style="width:100%; padding:12px 14px; border:1px solid var(--md-outline); border-radius:var(--md-radius-sm); background:var(--md-surface-container-high);">
                                <option value="">- None -</option>
                                @foreach ($priceLists as $pl)
                                    <option value="{{ $pl->id }}" @selected(old($field, $current) == $pl->id)>{{ $pl->code }} · {{ $pl->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    </div>
                @endif
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled">
                <span class="material-symbols-rounded">save</span> Save
            </button>
            <a href="{{ route('admin.stores.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection