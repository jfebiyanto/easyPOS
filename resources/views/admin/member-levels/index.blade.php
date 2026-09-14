@extends('layouts.admin')

@section('title', 'Level Member')
@section('page_title', 'Level Member')

@section('content')
<div class="tabs">
    <a href="{{ route('admin.customers.index') }}">Pelanggan</a>
    <a href="{{ route('admin.member-levels.index') }}" class="active">Level Member</a>
</div>

<div class="card">
    <h2 class="card-title">Konfigurasi Loyalitas</h2>
    <p class="card-subtitle">Perolehan poin memakai tarif per level. Poin belum dapat ditukar (redeem) pada MVP.</p>

    <form method="POST" action="{{ route('admin.member-levels.config') }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div class="field">
                <label for="min_spend">Minimal belanja untuk dapat poin (Rp)</label>
                <input type="number" step="0.01" min="0" id="min_spend" name="min_spend" value="{{ old('min_spend', $config->min_spend) }}">
            </div>
            <div class="field">
                <label for="rounding">Pembulatan poin</label>
                <select id="rounding" name="rounding">
                    @foreach (['down' => 'Bulatkan ke bawah', 'up' => 'Bulatkan ke atas', 'nearest' => 'Terdekat', 'none' => 'Tanpa pembulatan (2 desimal)'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('rounding', $config->rounding) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="expiry_days">Masa berlaku poin (hari, kosong = tanpa kedaluwarsa)</label>
                <input type="number" min="1" max="3650" id="expiry_days" name="expiry_days" value="{{ old('expiry_days', $config->expiry_days) }}">
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $config->is_active ?? true))>
                    <label for="is_active">Aktifkan program poin</label>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Simpan Konfigurasi</button>
        </div>
    </form>
</div>

<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Daftar Level</h2>
    <a href="{{ route('admin.member-levels.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Tambah Level
    </a>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>Level</th><th style="text-align:right;">Min. Poin</th><th style="text-align:right;">Rp per Poin</th><th>Status</th><th style="text-align:right;">Aksi</th></tr>
        </thead>
        <tbody>
            @forelse ($levels as $level)
                <tr>
                    <td><strong>{{ $level->name }}</strong></td>
                    <td style="text-align:right;" class="money">{{ rtrim(rtrim(number_format((float) $level->min_points, 2, ',', '.'), '0'), ',') }}</td>
                    <td style="text-align:right;" class="money">Rp {{ number_format((float) $level->earn_amount_per_point, 0, ',', '.') }}</td>
                    <td><span class="badge {{ $level->is_active ? 'active' : 'inactive' }}">{{ $level->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td style="text-align:right;">
                        <div class="actions-cell">
                            <a href="{{ route('admin.member-levels.edit', $level) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.member-levels.destroy', $level) }}" onsubmit="return confirm('Hapus level ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty-state">Belum ada level member.</div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
