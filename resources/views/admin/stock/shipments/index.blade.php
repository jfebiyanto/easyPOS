@extends('layouts.admin')

@section('title', 'Stock Transfer Shipment')
@section('page_title', 'Stock Transfer Shipment')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Shipment List</h2>
    <div style="display:flex; gap:12px; align-items:center;">
        <a href="{{ route('admin.stock-transfers.index') }}" style="font-size:12px; color:var(--color-text-muted);">Legacy transfers</a>
        <a href="{{ route('admin.stock-transfer-shipments.create') }}" class="btn btn-filled">
            <span class="material-symbols-rounded">add</span> Create Shipment
        </a>
    </div>
</div>

@php
    $chips = [
        'Draft' => 'chip-neutral',
        'In Transit' => 'chip-pending',
        'Partially Received' => 'chip-info',
        'Completed' => 'chip-success',
    ];
    $sapChips = [
        \App\Models\StockTransferShipment::SAP_PENDING => 'chip-pending',
        \App\Models\StockTransferShipment::SAP_POSTED => 'chip-success',
        \App\Models\StockTransferShipment::SAP_FAILED => 'chip-danger',
    ];
    $isOwner = auth()->user()->isOwner();
    $allowedStoreIds = $isOwner ? null : array_filter([auth()->user()->store_id]);
@endphp

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>Number</th>
                <th>Date</th>
                <th>From</th>
                <th>To</th>
                <th>Items</th>
                <th>Status</th>
                <th>SAP</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($shipments as $shipment)
                @php
                    $display = $shipment->displayStatus();
                    $isDraft = $shipment->status === \App\Models\StockTransferShipment::STATUS_DRAFT;
                    $isSender = $isOwner || in_array($shipment->from_store_id, $allowedStoreIds, true);
                @endphp
                <tr>
                    <td class="mono"><a href="{{ route('admin.stock-transfer-shipments.show', $shipment) }}">{{ $shipment->number ?? 'DRAFT-'.substr($shipment->id, 0, 8) }}</a></td>
                    <td class="mono">{{ ($shipment->posted_at ?? $shipment->created_at)?->format('d/m/Y') }}</td>
                    <td>{{ $shipment->fromStore?->code ?? '-' }}</td>
                    <td>{{ $shipment->toStore?->code ?? '-' }}</td>
                    <td>{{ $shipment->lines->count() }}</td>
                    <td><span class="chip {{ $chips[$display] ?? 'chip-neutral' }}">{{ $display }}</span></td>
                    <td>
                        @if ($shipment->sap_post_status)
                            <span class="chip {{ $sapChips[$shipment->sap_post_status] ?? 'chip-neutral' }}">{{ ucfirst($shipment->sap_post_status) }}</span>
                            @if ($shipment->sap_post_status === \App\Models\StockTransferShipment::SAP_POSTED && $shipment->sap_doc_num)
                                <span class="mono" style="font-size:12px;">{{ \App\Support\Sap\SapStockTransferPosting::OBJ_STOCK_TRANSFER }} {{ $shipment->sap_doc_num }}</span>
                            @endif
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        <div class="actions-cell">
                            @if ($isDraft && $isSender)
                                <form method="POST" action="{{ route('admin.stock-transfer-shipments.post', $shipment) }}" onsubmit="return confirm('Post this shipment? Stock leaves the source warehouse and goes in transit.')">
                                    @csrf
                                    <button type="submit" class="btn btn-filled btn-sm">Post</button>
                                </form>
                                <a href="{{ route('admin.stock-transfer-shipments.edit', $shipment) }}" class="btn btn-outline btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.stock-transfer-shipments.destroy', $shipment) }}" onsubmit="return confirm('Delete this draft shipment?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline btn-sm">Delete</button>
                                </form>
                            @endif
                            @if (in_array($display, ['In Transit', 'Partially Received'], true) && ($isOwner || in_array($shipment->to_store_id, $allowedStoreIds, true)))
                                <a href="{{ route('admin.stock-transfer-receipts.create', ['shipment' => $shipment->id]) }}" class="btn btn-filled btn-sm">Receive</a>
                            @endif
                            @if ($shipment->status === \App\Models\StockTransferShipment::STATUS_POSTED && $isSender && $shipment->lines->contains(fn ($line) => $line->remaining() > 0))
                                <a href="{{ route('admin.stock-transfer-returns.create', ['shipment' => $shipment->id]) }}" class="btn btn-outline btn-sm">Return</a>
                            @endif
                            @if ($isOwner && ! $isDraft && $shipment->sap_post_status !== \App\Models\StockTransferShipment::SAP_POSTED)
                                <form method="POST" action="{{ route('admin.stock-transfer-shipments.retry', $shipment) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-tonal btn-sm">Retry</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-rounded">local_shipping</span><p>No shipments yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $shipments])
@endsection
