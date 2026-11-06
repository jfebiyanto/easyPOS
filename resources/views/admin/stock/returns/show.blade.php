@extends('layouts.admin')

@section('title', 'Return')
@section('page_title', 'Return · '.$stockReturn->number)

@php
    $format = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
@endphp

@section('content')
<div class="card">
    <h2 class="card-title">Return Information</h2>
    <div class="form-grid">
        <div class="field"><label>Number</label><div class="mono">{{ $stockReturn->number }}</div></div>
        <div class="field"><label>Date</label><div class="mono">{{ $stockReturn->returned_at?->format('d/m/Y H:i') }}</div></div>
        <div class="field"><label>Shipment</label><div class="mono">{{ $stockReturn->shipment?->number ?? '-' }}</div></div>
        <div class="field"><label>Original Destination</label><div>{{ $stockReturn->shipment?->toStore?->code }} · {{ $stockReturn->shipment?->toStore?->name }}</div></div>
        <div class="field"><label>Returning Store</label><div>{{ $stockReturn->store?->code }} · {{ $stockReturn->store?->name }}</div></div>
        <div class="field">
            <label>SAP</label>
            <div>
                {{ $stockReturn->sap_post_status ? ucfirst($stockReturn->sap_post_status) : '-' }}
                @if ($stockReturn->sap_post_status === \App\Models\StockTransferReturn::SAP_POSTED && $stockReturn->sap_doc_num)
                    <span class="mono">{{ \App\Support\Sap\SapStockTransferPosting::OBJ_STOCK_TRANSFER }} {{ $stockReturn->sap_doc_num }}</span>
                @elseif ($stockReturn->shipment && $stockReturn->shipment->sap_post_status !== \App\Models\StockTransferShipment::SAP_POSTED)
                    <div class="hint">Waits for shipment {{ $stockReturn->shipment->number }} to be posted to SAP.</div>
                @endif
            </div>
        </div>
        @if ($stockReturn->note)
            <div class="field" style="grid-column:1/-1;"><label>Notes</label><div>{{ $stockReturn->note }}</div></div>
        @endif
    </div>
</div>

<div class="card">
    <h2 class="card-title">Product Lines</h2>
    <div class="table-wrap">
        <table class="md-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th style="text-align:right;">Returned</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($stockReturn->lines as $line)
                    <tr>
                        <td>{{ $line->shipmentLine?->product?->sku }} · {{ $line->shipmentLine?->product?->name }}</td>
                        <td style="text-align:right;" class="money">{{ $format($line->qty) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="form-actions" style="margin-top:20px;">
        @if (auth()->user()->isOwner() && $stockReturn->sap_post_status !== \App\Models\StockTransferReturn::SAP_POSTED)
            <form method="POST" action="{{ route('admin.stock-transfer-returns.retry', $stockReturn) }}">
                @csrf
                <button type="submit" class="btn btn-tonal">Retry SAP Posting</button>
            </form>
        @endif
        <a href="{{ route('admin.stock-transfer-returns.index') }}" class="btn btn-outline">Back</a>
    </div>
</div>
@endsection
