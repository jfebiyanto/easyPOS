@extends('layouts.admin')

@section('title', 'Detail Pelanggan')
@section('page_title', 'Detail Pelanggan · '.$customer->name)

@section('content')
<div class="tabs">
    <a href="{{ route('admin.customers.index') }}">Pelanggan</a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon"><span class="material-symbols-rounded">loyalty</span></div>
        <div>
            <div class="stat-value money">{{ rtrim(rtrim(number_format((float) $customer->points_balance, 2, ',', '.'), '0'), ',') }}</div>
            <div class="stat-label">Poin saat ini</div>
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
    <h2 class="card-title">Profil</h2>
    <div class="form-grid">
        <div><strong>Kode</strong><div class="mono">{{ $customer->code ?? '-' }}</div></div>
        <div><strong>No. HP</strong><div class="mono">{{ $customer->phone ?? '-' }}</div></div>
        <div><strong>Email</strong><div>{{ $customer->email ?? '-' }}</div></div>
        <div><strong>Status</strong><div>{{ $customer->is_active ? 'Aktif' : 'Nonaktif' }}{{ $customer->is_member ? ' · Member' : '' }}</div></div>
        <div style="grid-column:1/-1;"><strong>Alamat</strong><div>{{ $customer->address ?? '-' }}</div></div>
    </div>
</div>

@unless ($readOnly)
<div class="card">
    <h2 class="card-title">Penyesuaian Poin</h2>
    <form method="POST" action="{{ route('admin.customers.adjust-points', $customer) }}">
        @csrf
        <div class="form-grid">
            <div class="field @error('points') has-error @enderror">
                <label for="points">Jumlah Poin (boleh negatif)</label>
                <input type="number" step="0.01" id="points" name="points" required>
                @error('points') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field @error('note') has-error @enderror" style="grid-column:1/-1;">
                <label for="note">Alasan *</label>
                <input type="text" id="note" name="note" required maxlength="500">
                @error('note') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">add</span> Sesuaikan Poin</button>
        </div>
    </form>
</div>
@endunless

<div class="card">
    <h2 class="card-title">Riwayat Poin</h2>
    <div class="table-wrap">
        <table class="md-table">
            <thead>
                <tr><th>Waktu</th><th>Tipe</th><th style="text-align:right;">Poin</th><th style="text-align:right;">Saldo</th><th>Catatan</th></tr>
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
                    <tr><td colspan="5"><div class="empty-state">Belum ada riwayat poin.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="form-actions">
    <a href="{{ route('admin.customers.edit', $customer) }}" class="btn btn-tonal">Edit</a>
    <a href="{{ route('admin.customers.index') }}" class="btn btn-outline">Kembali</a>
</div>
@endsection
