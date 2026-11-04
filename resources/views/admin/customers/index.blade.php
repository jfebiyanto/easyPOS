@extends('layouts.admin')

@section('title', 'Daftar Pelanggan')
@section('page_title', 'Daftar Pelanggan')

@section('content')
<div class="tabs">
    <a href="{{ route('admin.customers.index') }}" class="active">Pelanggan</a>
    @if (auth()->user()->isSuperuser())
        <a href="{{ route('admin.member-levels.index') }}">Level Member</a>
    @endif
</div>

<div class="table-toolbar">
    <form method="GET" action="{{ route('admin.customers.index') }}" class="search-box">
        <div class="field" style="margin-bottom:0; display:flex; gap:8px;">
            <input type="text" name="q" placeholder="Cari nama, HP, atau kode…" value="{{ request('q') }}">
            <select name="level" style="max-width:180px;" onchange="this.form.submit()">
                <option value="">Semua Level</option>
                @foreach ($levels as $level)
                    <option value="{{ $level->id }}" @selected(request('level') == $level->id)>{{ $level->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-tonal"><span class="material-symbols-rounded">search</span></button>
        </div>
    </form>
    <a href="{{ route('admin.customers.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">person_add</span> Tambah Pelanggan
    </a>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>{!! sort_link('Kode', 'code') !!}</th>
                <th>{!! sort_link('Nama', 'name') !!}</th>
                <th>{!! sort_link('No. HP', 'phone') !!}</th>
                <th>Level</th>
                <th>{!! sort_link('Poin', 'points_balance') !!}</th>
                <th>Transaksi</th>
                <th>{!! sort_link('Status', 'is_active') !!}</th>
                <th style="text-align:right;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($customers as $customer)
                <tr>
                    <td class="mono">
                        {{ $customer->code ?? '-' }}
                        @if (! $customer->isFromSap() && in_array($customer->sap_sync_status, ['pending', 'failed'], true))
                            <span class="chip {{ $customer->sap_sync_status === 'failed' ? 'chip-danger' : 'chip-neutral' }}" title="{{ $customer->sap_sync_error }}">{{ $customer->sap_sync_status === 'failed' ? 'Gagal ke SAP' : 'Menunggu SAP' }}</span>
                        @endif
                    </td>
                    <td><strong>{{ $customer->name }}</strong></td>
                    <td class="mono">{{ $customer->phone ?? '-' }}</td>
                    <td><span class="chip chip-info">{{ $customer->memberLevel?->name ?? '-' }}</span></td>
                    <td class="money">{{ rtrim(rtrim(number_format((float) $customer->points_balance, 2, ',', '.'), '0'), ',') }}</td>
                    <td>{{ $customer->sales_count }}</td>
                    <td><span class="badge {{ $customer->is_active ? 'active' : 'inactive' }}">{{ $customer->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td style="text-align:right;">
                        <div class="actions-cell">
                            <a href="{{ route('admin.customers.show', $customer) }}" class="btn btn-tonal btn-sm">Detail</a>
                            <a href="{{ route('admin.customers.edit', $customer) }}" class="btn btn-tonal btn-sm">Edit</a>
                            <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}" onsubmit="return confirm('Hapus pelanggan ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-rounded">person_off</span><p>Belum ada pelanggan.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $customers])
@endsection
