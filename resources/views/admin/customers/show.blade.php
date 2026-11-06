@extends('layouts.admin')

@section('title', 'Customer Details')
@section('page_title', 'Detail Pelanggan · '.$customer->name)

@section('content')
<div class="tabs">
    <a href="{{ route('admin.customers.index') }}">Customer</a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">loyalty</span></div>
        <div>
            <div class="stat-value money">{{ rtrim(rtrim(number_format((float) $customer->points_balance, 2, ',', '.'), '0'), ',') }}</div>
            <div class="stat-label">Current points</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">workspace_premium</span></div>
        <div>
            <div class="stat-value">{{ $customer->memberLevel?->name ?? '-' }}</div>
            <div class="stat-label">Level member</div>
        </div>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Profile</h2>
    <div class="form-grid">
        <div><strong>Code</strong><div class="mono">{{ $customer->code ?? '-' }}</div></div>
        <div><strong>Mobile No.</strong><div class="mono">{{ $customer->phone ?? '-' }}</div></div>
        <div><strong>Email</strong><div>{{ $customer->email ?? '-' }}</div></div>
        <div><strong>Status</strong><div>{{ $customer->is_active ? 'Aktif' : 'Nonaktif' }}{{ $customer->is_member ? ' · Member' : '' }}</div></div>
        <div style="grid-column:1/-1;"><strong>Address</strong><div>{{ $customer->address ?? '-' }}</div></div>
    </div>
</div>

@unless ($readOnly)
<div class="card">
    <h2 class="card-title">Points Adjustment</h2>
    <form method="POST" action="{{ route('admin.customers.adjust-points', $customer) }}">
        @csrf
        <div class="form-grid">
            <div class="field @error('points') has-error @enderror">
                <label for="points">Points (may be negative)</label>
                <input type="number" step="0.01" id="points" name="points" required>
                @error('points') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('note') has-error @enderror" style="grid-column:1/-1;">
                <label for="note">Reason *</label>
                <input type="text" id="note" name="note" required maxlength="500">
                @error('note') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">add</span> Adjust Points</button>
        </div>
    </form>
</div>
@endunless

<div class="card">
    <h2 class="card-title">Points History</h2>
    <div class="table-wrap">
        <table class="md-table">
            <thead>
                <tr><th>Time</th><th>Type</th><th style="text-align:right;">Points</th><th style="text-align:right;">Balance</th><th>Notes</th></tr>
            </thead>
            <tbody>
                @forelse ($ledger as $entry)
                    <tr>
                        <td class="mono">{{ $entry->created_at?->format('d/m/Y H:i') }}</td>
                        <td><span class="chip chip-neutral">{{ $entry->type }}</span></td>
                        <td style="text-align:right;" class="money {{ (float) $entry->points < 0 ? 'neg' : 'pos' }}">
                            {{ (float) $entry->points > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $entry->points, 2, ',', '.'), '0'), ',') }}
                        </td>
                        <td style="text-align:right;" class="money">{{ rtrim(rtrim(number_format((float) $entry->balance_after, 2, ',', '.'), '0'), ',') }}</td>
                        <td>{{ $entry->note ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state">No points history yet.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="form-actions">
    <a href="{{ route('admin.customers.edit', $customer) }}" class="btn btn-tonal">Edit</a>
    <a href="{{ route('admin.customers.index') }}" class="btn btn-outline">Back</a>
</div>
@endsection
