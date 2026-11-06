@extends('layouts.admin')

@section('title', 'Legacy Stock Transfers')
@section('page_title', 'Legacy Stock Transfers')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Legacy Stock Transfers</h2>
    <a href="{{ route('admin.stock-transfer-shipments.index') }}" class="btn btn-outline">Stock Transfer Shipment</a>
</div>

<p style="margin:0 0 12px; padding:10px 14px; border-radius:8px; background:var(--color-surface-variant, #eef1f5); font-size:13px;">
    Read-only — replaced by Stock Transfer Shipment / Stock Transfer Receipt.
</p>

@php
    $chips = [
        'draft' => ['chip-neutral', 'Draft'],
        'requested' => ['chip-info', 'Requested'],
        'shipped' => ['chip-pending', 'Shipped'],
        'received' => ['chip-success', 'Received'],
        'cancelled' => ['chip-danger', 'Cancelled'],
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
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-rounded">swap_horiz</span><p>No stock transfers yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $transfers])
@endsection
