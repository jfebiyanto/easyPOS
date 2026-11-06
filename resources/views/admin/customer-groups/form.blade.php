@extends('layouts.admin')

@section('title', $group->exists ? 'Edit Grup Pelanggan' : 'Tambah Grup Pelanggan')
@section('page_title', $group->exists ? 'Edit Grup Pelanggan' : 'Tambah Grup Pelanggan')

@php $fromSap = $group->isFromSap(); @endphp

@section('content')
<div class="card">
    <h2 class="card-title">{{ $group->exists ? 'Edit Customer Group' : 'New Customer Group' }}</h2>
    <p class="card-subtitle">The default group is preselected when creating a new customer, from both admin and POS.</p>

    @if ($fromSap)
        <div class="md-snackbar info">
            <span class="material-symbols-rounded">lock</span>
            Group from SAP B1: code, name and status can only be changed in SAP B1. The default flag can still be set in easyPOS.
        </div>
    @endif

    <form method="POST" action="{{ $group->exists ? route('admin.customer-groups.update', $group) : route('admin.customer-groups.store') }}">
        @csrf
        @if ($group->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Code *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $group->code) }}" required maxlength="30" @readonly($fromSap)>
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $group->name) }}" required maxlength="150" @readonly($fromSap)>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $group->is_active)) @disabled($fromSap)>
                    <label for="is_active">Active</label>
                </div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_default" name="is_default" value="1" @checked(old('is_default', $group->is_default))>
                    <label for="is_default">Default for new customers</label>
                </div>
                <div class="hint">Only one group can be the default; the previous default is cleared automatically.</div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save</button>
            <a href="{{ route('admin.customer-groups.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
