@extends('layouts.admin')

@section('title', 'Member Tiers')
@section('page_title', 'Member Tiers')

@section('content')
<div class="tabs">
    <a href="{{ route('admin.customers.index') }}">Customer</a>
    <a href="{{ route('admin.member-levels.index') }}" class="active">Member Tier</a>
</div>

<div class="card">
    <h2 class="card-title">Loyalty Configuration</h2>
    <p class="card-subtitle">Points are earned at a per-tier rate. Points cannot be redeemed yet in the MVP.</p>

    <form method="POST" action="{{ route('admin.member-levels.config') }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="field">
                <label for="min_spend">Minimum spend to earn points (Rp)</label>
                <input type="number" step="0.01" min="0" id="min_spend" name="min_spend" value="{{ old('min_spend', $config->min_spend) }}">
            </div>
            <div class="field">
                <label for="rounding">Points rounding</label>
                <select id="rounding" name="rounding">
                    @foreach (['down' => 'Round down', 'up' => 'Round up', 'nearest' => 'Nearest', 'none' => 'No rounding (2 decimals)'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('rounding', $config->rounding) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="expiry_days">Points validity (days)</label>
                <input type="number" min="1" max="3650" id="expiry_days" name="expiry_days" value="{{ old('expiry_days', $config->expiry_days) }}">
                <div class="hint">Leave blank if points never expire.</div>
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $config->is_active ?? true))>
                    <label for="is_active">Enable points program</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            @unless ($readOnly)
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save Configuration</button>
            @endunless
        </div>
    </form>
</div>

<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Tier List</h2>
    @unless ($readOnly)
    <a href="{{ route('admin.member-levels.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Add Tier
    </a>
    @endunless
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>Tier</th><th style="text-align:right;">Min. Points</th><th style="text-align:right;">Rp per Point</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($levels as $level)
                <tr>
                    <td><strong>{{ $level->name }}</strong></td>
                    <td style="text-align:right;" class="money">{{ rtrim(rtrim(number_format((float) $level->min_points, 2, ',', '.'), '0'), ',') }}</td>
                    <td style="text-align:right;" class="money">Rp {{ number_format((float) $level->earn_amount_per_point, 0, ',', '.') }}</td>
                    <td><span class="badge {{ $level->is_active ? 'active' : 'inactive' }}">{{ $level->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td style="text-align:right;">
                        <div class="actions-cell">
                            @unless ($readOnly)
                            <a href="{{ route('admin.member-levels.edit', $level) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.member-levels.destroy', $level) }}" onsubmit="return confirm('Delete this tier?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty-state">No member tiers yet.</div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
