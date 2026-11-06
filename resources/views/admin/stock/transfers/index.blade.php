@extends('layouts.admin')

@section('title', 'Stock Transfers')
@section('page_title', 'Stock Transfers')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Transfer List</h2>
    <a href="{{ route('admin.stock-transfers.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Create Transfer
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
                <th>Number</th>
                <th>From</th>
                <th>To</th>
                <th>Items</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
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
                                <form method="POST" action="{{ route('admin.stock-transfers.ship', $tr) }}" onsubmit="return confirm('Ship this transfer? Stock leaves the source store.')">
                                    @csrf
                                    <button type="submit" class="btn btn-filled btn-sm">Ship</button>
                                </form>
                                <form method="POST" action="{{ route('admin.stock-transfers.cancel', $tr) }}" onsubmit="return confirm('Cancel this transfer?')">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm">Cancel</button>
                                </form>
                            @elseif ($tr->status === 'shipped')
                                <a href="{{ route('admin.stock-transfers.receive-form', $tr) }}" class="btn btn-filled btn-sm">Receive</a>
                            @else
                                <span style="color:var(--color-text-muted); font-size:12px;">Completed</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">swap_horiz</span><p>No stock transfers yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $transfers])
@endsection
