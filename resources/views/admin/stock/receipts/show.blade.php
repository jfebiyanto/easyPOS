@extends('layouts.admin')

@section('title', 'Receipt')
@section('page_title', 'Receipt · '.$receipt->number)

@php
    $format = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
@endphp

@section('content')
<div class="card">
    <h2 class="card-title">Receipt Information</h2>
    <div class="form-grid">
        <div class="field"><label>Number</label><div class="mono">{{ $receipt->number }}</div></div>
        <div class="field"><label>Date</label><div class="mono">{{ $receipt->received_at?->format('d/m/Y H:i') }}</div></div>
        <div class="field"><label>Shipment</label><div class="mono">{{ $receipt->shipment?->number ?? '-' }}</div></div>
        <div class="field"><label>From</label><div>{{ $receipt->shipment?->fromStore?->code }} · {{ $receipt->shipment?->fromStore?->name }}</div></div>
        <div class="field"><label>Receiving Store</label><div>{{ $receipt->store?->code }} · {{ $receipt->store?->name }}</div></div>
        <div class="field">
            <label>SAP</label>
            <div>
                {{ $receipt->sap_post_status ? ucfirst($receipt->sap_post_status) : '-' }}
                @if ($receipt->sap_post_status === \App\Models\StockTransferReceipt::SAP_POSTED && $receipt->sap_doc_num)
                    <span class="mono">{{ \App\Support\Sap\SapStockTransferPosting::OBJ_STOCK_TRANSFER }} {{ $receipt->sap_doc_num }}</span>
                @elseif ($receipt->shipment && $receipt->shipment->sap_post_status !== \App\Models\StockTransferShipment::SAP_POSTED)
                    <div class="hint">Waits for shipment {{ $receipt->shipment->number }} to be posted to SAP.</div>
                @endif
            </div>
        </div>
        @if ($receipt->note)
            <div class="field" style="grid-column:1/-1;"><label>Notes</label><div>{{ $receipt->note }}</div></div>
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
                    <th style="text-align:right;">Received</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($receipt->lines as $line)
                    <tr>
                        <td>{{ $line->shipmentLine?->product?->sku }} · {{ $line->shipmentLine?->product?->name }}</td>
                        <td style="text-align:right;" class="money">{{ $format($line->qty) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="form-actions" style="margin-top:20px;">
        <a href="{{ route('admin.stock-transfer-receipts.index') }}" class="btn btn-outline">Back</a>
    </div>
</div>
@endsection
