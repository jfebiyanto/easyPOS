@extends('layouts.admin')

@section('title', 'Nominal Pecahan: '.$currency->code)
@section('page_title', 'Nominal Pecahan · '.$currency->code.' ('.$currency->symbol.')')

@section('content')
@if (auth()->user()->isSuperuser())
    <div class="card">
        <h2 class="card-title">Add Denomination</h2>
        <p class="card-subtitle">Currency: <strong>{{ $currency->code }} ({{ $currency->symbol }})</strong> — denominations accepted at the register.</p>

        <form method="POST" action="{{ route('admin.currencies.denominations.store', $currency) }}">
            @csrf
            <div class="form-grid">
                <div class="field @error('value') has-error @enderror">
                    <label for="value">Denomination *</label>
                    <input type="number" id="value" name="value" step="0.01" min="0.01" required value="{{ old('value') }}" placeholder="100000">
                    @error('value') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <div class="checkbox-row">
                        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', true))>
                        <label for="is_active">Active</label>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save Denomination</button>
            </div>
        </form>
    </div>
@else
    <div class="md-snackbar error">
        <span class="material-symbols-rounded">lock</span>
        You are a non-superuser Admin: denominations are read-only.
    </div>
@endif

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>Amount</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($currency->denominations as $denomination)
                <tr>
                    <td><strong>{{ $currency->symbol }} {{ number_format($denomination->value, $currency->decimal_places, ',', '.') }}</strong></td>
                    <td><span class="badge {{ $denomination->is_active ? 'active' : 'inactive' }}">{{ $denomination->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @if (auth()->user()->isSuperuser())
                                <form method="POST" action="{{ route('admin.currencies.denominations.destroy', [$currency, $denomination]) }}" onsubmit="return confirm('Delete this denomination?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3"><div class="empty-state"><span class="material-symbols-rounded">payments</span><p>No denominations yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="form-actions" style="margin-top: 16px;">
    <a href="{{ route('admin.currencies.index') }}" class="btn btn-outline">Back</a>
</div>
@endsection
