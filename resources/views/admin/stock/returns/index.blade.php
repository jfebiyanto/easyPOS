@extends('layouts.admin')

@section('title', 'Stock Transfer Return')
@section('page_title', 'Stock Transfer Return')

@section('content')
@php
    $sapChips = [
        \App\Models\StockTransferReturn::SAP_PENDING => 'chip-pending',
        \App\Models\StockTransferReturn::SAP_POSTED => 'chip-success',
        \App\Models\StockTransferReturn::SAP_FAILED => 'chip-danger',
    ];
    $isOwner = auth()->user()->isOwner();
@endphp

<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Return List</h2>
    <a href="{{ route('admin.stock-transfer-returns.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> New Return
    </a>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>Number</th>
                <th>Date</th>
                <th>Shipment</th>
                <th>Returning Store</th>
                <th>Items</th>
                <th>SAP</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($returns as $stockReturn)
                <tr>
                    <td class="mono"><a href="{{ route('admin.stock-transfer-returns.show', $stockReturn) }}">{{ $stockReturn->number }}</a></td>
                    <td class="mono">{{ $stockReturn->returned_at?->format('d/m/Y') }}</td>
                    <td class="mono">{{ $stockReturn->shipment?->number ?? '-' }}</td>
                    <td>{{ $stockReturn->store?->code ?? '-' }}</td>
                    <td>{{ $stockReturn->lines_count }}</td>
                    <td>
                        @if ($stockReturn->sap_post_status)
                            <span class="chip {{ $sapChips[$stockReturn->sap_post_status] ?? 'chip-neutral' }}">{{ ucfirst($stockReturn->sap_post_status) }}</span>
                            @if ($stockReturn->sap_post_status === \App\Models\StockTransferReturn::SAP_POSTED && $stockReturn->sap_doc_num)
                                <span class="mono" style="font-size:12px;">{{ \App\Support\Sap\SapStockTransferPosting::OBJ_STOCK_TRANSFER }} {{ $stockReturn->sap_doc_num }}</span>
                            @endif
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        <div class="actions-cell">
                            @if ($isOwner && $stockReturn->sap_post_status !== \App\Models\StockTransferReturn::SAP_POSTED)
                                <form method="POST" action="{{ route('admin.stock-transfer-returns.retry', $stockReturn) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-tonal btn-sm">Retry</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">keyboard_return</span><p>No returns yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $returns])
@endsection
