@extends('layouts.admin')

@section('title', 'Stock Transfer Receipt')
@section('page_title', 'Stock Transfer Receipt')

@section('content')
@php
    $sapChips = [
        \App\Models\StockTransferReceipt::SAP_PENDING => 'chip-pending',
        \App\Models\StockTransferReceipt::SAP_POSTED => 'chip-success',
        \App\Models\StockTransferReceipt::SAP_FAILED => 'chip-danger',
    ];
    $isOwner = auth()->user()->isOwner();
@endphp

<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Receipt List</h2>
    <a href="{{ route('admin.stock-transfer-receipts.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> New Receipt
    </a>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>Number</th>
                <th>Date</th>
                <th>Shipment</th>
                <th>Store</th>
                <th>Items</th>
                <th>SAP</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($receipts as $receipt)
                <tr>
                    <td class="mono"><a href="{{ route('admin.stock-transfer-receipts.show', $receipt) }}">{{ $receipt->number }}</a></td>
                    <td class="mono">{{ $receipt->received_at?->format('d/m/Y') }}</td>
                    <td class="mono">{{ $receipt->shipment?->number ?? '-' }}</td>
                    <td>{{ $receipt->store?->code ?? '-' }}</td>
                    <td>{{ $receipt->lines_count }}</td>
                    <td>
                        @if ($receipt->sap_post_status)
                            <span class="chip {{ $sapChips[$receipt->sap_post_status] ?? 'chip-neutral' }}">{{ ucfirst($receipt->sap_post_status) }}</span>
                            @if ($receipt->sap_post_status === \App\Models\StockTransferReceipt::SAP_POSTED && $receipt->sap_doc_num)
                                <span class="mono" style="font-size:12px;">{{ \App\Support\Sap\SapStockTransferPosting::OBJ_STOCK_TRANSFER }} {{ $receipt->sap_doc_num }}</span>
                            @endif
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        <div class="actions-cell">
                            @if ($isOwner && $receipt->sap_post_status !== \App\Models\StockTransferReceipt::SAP_POSTED)
                                <form method="POST" action="{{ route('admin.stock-transfer-receipts.retry', $receipt) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-tonal btn-sm">Retry</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">move_to_inbox</span><p>No receipts yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $receipts])
@endsection
