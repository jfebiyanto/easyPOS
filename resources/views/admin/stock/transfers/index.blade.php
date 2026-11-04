@extends('layouts.admin')

@section('title', 'Transfer Stok')
@section('page_title', 'Transfer Stok')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Daftar Transfer</h2>
    <a href="{{ route('admin.stock-transfers.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Buat Transfer
    </a>
</div>

@php
    $chips = [
        'draft' => ['chip-neutral', 'Draft'],
        'requested' => ['chip-info', 'Diminta'],
        'shipped' => ['chip-pending', 'Dikirim'],
        'received' => ['chip-success', 'Diterima'],
        'cancelled' => ['chip-danger', 'Dibatalkan'],
    ];
@endphp

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>Nomor</th>
                <th>Dari</th>
                <th>Ke</th>
                <th>Item</th>
                <th>Status</th>
                <th style="text-align:right;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transfers as $tr)
                @php [$chip, $label] = $chips[$tr->status] ?? ['chip-neutral', $tr->status]; @endphp
                <tr>
                    <td class="mono">{{ $tr->number ?? 'DRAFT-'.substr($tr->id, 0, 8) }}</td>
                    <td>{{ $tr->fromStore?->code ?? '-' }}</td>
                    <td>{{ $tr->toStore?->code ?? '-' }}</td>
                    <td>{{ $tr->lines->count() }}</td>
                    <td><span class="chip {{ $chip }}">{{ $label }}</span></td>
                    <td>
                        <div class="actions-cell">
                            @if (in_array($tr->status, ['draft', 'requested']))
                                <form method="POST" action="{{ route('admin.stock-transfers.ship', $tr) }}" onsubmit="return confirm('Kirim transfer ini? Stok keluar dari gerai asal.')">
                                    @csrf
                                    <button type="submit" class="btn btn-filled btn-sm">Kirim</button>
                                </form>
                                <form method="POST" action="{{ route('admin.stock-transfers.cancel', $tr) }}" onsubmit="return confirm('Batalkan transfer ini?')">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm">Batal</button>
                                </form>
                            @elseif ($tr->status === 'shipped')
                                <a href="{{ route('admin.stock-transfers.receive-form', $tr) }}" class="btn btn-filled btn-sm">Terima</a>
                            @else
                                <span style="color:var(--color-text-muted); font-size:12px;">Selesai</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">swap_horiz</span><p>Belum ada transfer stok.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $transfers])
@endsection
