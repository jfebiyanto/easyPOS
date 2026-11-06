@extends('layouts.admin')

@section('title', $taxGroup->exists ? 'Edit Tax Group' : 'Add Tax Group')
@section('page_title', $taxGroup->exists ? 'Edit Tax Group' : 'Add Tax Group')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $taxGroup->exists ? 'Edit Tax Group' : 'New Tax Group' }}</h2>
    <p class="card-subtitle">Output Tax codes used in Tax Settings and recorded on every transaction line.</p>

    <form method="POST" action="{{ $taxGroup->exists ? route('admin.tax-groups.update', $taxGroup) : route('admin.tax-groups.store') }}">
        @csrf
        @if ($taxGroup->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('code') has-error @enderror">
                <label for="code">Code *</label>
                <input type="text" id="code" name="code" value="{{ old('code', $taxGroup->code) }}" required maxlength="30">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('name') has-error @enderror">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $taxGroup->name) }}" required maxlength="150">
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('rate') has-error @enderror">
                <label for="rate">Rate (%) *</label>
                <input type="number" id="rate" name="rate" step="0.0001" min="0" max="100" value="{{ old('rate', $taxGroup->exists ? (float) $taxGroup->rate : '') }}" required>
                @error('rate') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('effective_from') has-error @enderror">
                <label for="effective_from">Effective since</label>
                <input type="date" id="effective_from" name="effective_from" value="{{ old('effective_from', $taxGroup->effective_from?->toDateString()) }}">
                @error('effective_from') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $taxGroup->exists ? $taxGroup->is_active : true))>
                    <label for="is_active">Active</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save</button>
            <a href="{{ route('admin.tax-groups.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
