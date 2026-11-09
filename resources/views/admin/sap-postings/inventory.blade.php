@extends('layouts.admin')

@section('title', 'Integration Monitor')
@section('page_title', 'Integration Monitor')

@php
    $canManage = auth()->user()->isOwner();
    $statusLabels = ['pending' => 'Pending', 'posted' => 'Posted', 'failed' => 'Failed'];
    $statusChips = ['pending' => 'chip-neutral', 'posted' => 'chip-success', 'failed' => 'chip-danger'];
    $kindLabels = ['shipment' => 'Transfer Shipment', 'receipt' => 'Transfer Receipt', 'return' => 'Transfer Return', 'adjustment' => 'Stock Adjustment'];
    $retryRoutes = [
        'shipment' => 'admin.stock-transfer-shipments.retry',
        'receipt' => 'admin.stock-transfer-receipts.retry',
        'return' => 'admin.stock-transfer-returns.retry',
        'adjustment' => 'admin.stock-adjustments.retry',
    ];
@endphp

@section('content')
<div class="tabs">
    <a href="{{ route('admin.sap-postings.index') }}">Sales</a>
    <a href="{{ route('admin.sap-postings.returns') }}">Returns</a>
    <a href="{{ route('admin.sap-postings.inventory') }}" class="active">Inventory</a>
</div>
<div class="tabs">
    <a href="{{ route('admin.sap-postings.inventory') }}" class="{{ $status ? '' : 'active' }}">All</a>
    @foreach ($statusLabels as $key => $label)
        <a href="{{ route('admin.sap-postings.inventory', ['status' => $key]) }}" class="{{ $status === $key ? 'active' : '' }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
    @endforeach
</div>

<div class="table-wrap">
    <table class="md-table sap-monitor">
        <thead>
            <tr>
                <th>Document</th>
                <th>No.</th>
                <th>Date</th>
                <th>Store</th>
                <th>Status</th>
                <th>SAP Document</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td style="white-space:nowrap;">{{ $kindLabels[$row->kind] ?? $row->kind }}</td>
                    <td class="mono" style="white-space:nowrap;">{{ $row->number ?? '-' }}</td>
                    <td class="mono">{{ $row->event_at ? \Illuminate\Support\Carbon::parse($row->event_at)->format('d/m/Y') : '-' }}</td>
                    <td>{{ $stores[$row->store_id] ?? '-' }}</td>
                    <td style="min-width:180px; max-width:260px;">
                        <span class="chip {{ $statusChips[$row->sap_post_status] ?? 'chip-neutral' }}">{{ $statusLabels[$row->sap_post_status] ?? $row->sap_post_status }}</span>
                        @if ($row->sap_post_error)
                            <div class="hint" style="color: var(--color-danger);">{{ $row->sap_post_error }}</div>
                        @endif
                    </td>
                    <td class="mono">
                        @if ($row->kind === 'adjustment')
                            @if ($row->doc_a) <div>Goods Receipt {{ $row->doc_a }}</div> @endif
                            @if ($row->doc_b) <div>Goods Issue {{ $row->doc_b }}</div> @endif
                            @if (! $row->doc_a && ! $row->doc_b) - @endif
                        @else
                            {{ $row->doc_a ? 'Stock Transfer '.$row->doc_a : '-' }}
                        @endif
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        @if ($canManage && $row->sap_post_status === 'failed')
                            <form method="POST" action="{{ route($retryRoutes[$row->kind], $row->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm"><span class="material-symbols-rounded">sync</span> Resend</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">inventory_2</span><p>No inventory documents have been posted to SAP B1 yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $rows->links() }}
@endsection
