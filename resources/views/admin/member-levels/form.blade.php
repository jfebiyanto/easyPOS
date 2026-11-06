@extends('layouts.admin')

@section('title', $level->exists ? 'Edit Tier' : 'Add Tier')
@section('page_title', $level->exists ? 'Edit Tier' : 'Add Tier')

@section('content')
<div class="card">
    <h2 class="card-title">{{ $level->exists ? 'Edit Member Tier' : 'New Member Tier' }}</h2>
    <p class="card-subtitle">Points earning rate: how many Rupiah of spending earn 1 point.</p>

    <form method="POST" action="{{ $level->exists ? route('admin.member-levels.update', $level) : route('admin.member-levels.store') }}">
        @csrf
        @if ($level->exists) @method('PUT') @endif

        <div class="form-grid">
            <div class="field @error('name') has-error @enderror">
                <label for="name">Tier Name *</label>
                <input type="text" id="name" name="name" value="{{ old('name', $level->name) }}" required maxlength="60" placeholder="e.g. Silver">
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('min_points') has-error @enderror">
                <label for="min_points">Minimum Points *</label>
                <input type="number" step="0.01" min="0" id="min_points" name="min_points" value="{{ old('min_points', $level->min_points ?? 0) }}" required>
                @error('min_points') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('earn_amount_per_point') has-error @enderror">
                <label for="earn_amount_per_point">Rupiah per 1 Point *</label>
                <input type="number" step="0.01" min="1" id="earn_amount_per_point" name="earn_amount_per_point" value="{{ old('earn_amount_per_point', $level->earn_amount_per_point ?? 10000) }}" required>
                @error('earn_amount_per_point') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label for="order">Order</label>
                <input type="number" min="0" id="order" name="order" value="{{ old('order', $level->order ?? 0) }}">
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $level->exists ? $level->is_active : true))>
                    <label for="is_active">Active</label>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save</button>
            <a href="{{ route('admin.member-levels.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
