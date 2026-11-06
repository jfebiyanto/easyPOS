@extends('layouts.admin')

@section('title', 'Shipment')
@section('page_title', 'Shipment · '.($shipment->number ?? 'Draft'))

@section('content')
<div class="card">
    <h2 class="card-title">Shipment Information</h2>
    <div class="form-grid">
        <div class="field"><label>Number</label><div class="mono">{{ $shipment->number ?? '-' }}</div></div>
        <div class="field"><label>Status</label><div>{{ $shipment->displayStatus() }}</div></div>
        <div class="field"><label>From</label><div>{{ $shipment->fromStore?->code }} · {{ $shipment->fromStore?->name }}</div></div>
        <div class="field"><label>To</label><div>{{ $shipment->toStore?->code }} · {{ $shipment->toStore?->name }}</div></div>
        <div class="field">
            <label>SAP</label>
            <div>
                {{ $shipment->sap_post_status ? ucfirst($shipment->sap_post_status) : '-' }}
                @if ($shipment->sap_post_status === \App\Models\StockTransferShipment::SAP_POSTED && $shipment->sap_doc_num)
                    <span class="mono">{{ \App\Support\Sap\SapStockTransferPosting::OBJ_STOCK_TRANSFER }} {{ $shipment->sap_doc_num }}</span>
                @endif
            </div>
        </div>
        @if ($shipment->note)
            <div class="field" style="grid-column:1/-1;"><label>Notes</label><div>{{ $shipment->note }}</div></div>
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
                    <th style="text-align:right;">Shipped</th>
                    <th style="text-align:right;">Received</th>
                    <th style="text-align:right;">Remaining</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($shipment->lines as $line)
                    <tr>
                        <td>{{ $line->product?->sku }} · {{ $line->product?->name }}</td>
                        <td style="text-align:right;" class="money">{{ rtrim(rtrim(number_format((float) $line->qty, 3, ',', '.'), '0'), ',') }}</td>
                        <td style="text-align:right;" class="money">{{ rtrim(rtrim(number_format((float) $line->qty_received, 3, ',', '.'), '0'), ',') }}</td>
                        <td style="text-align:right;" class="money">{{ rtrim(rtrim(number_format($line->remaining(), 3, ',', '.'), '0'), ',') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@if ($shipment->status === \App\Models\StockTransferShipment::STATUS_POSTED)
    @php $shipmentInSap = $shipment->sap_post_status === \App\Models\StockTransferShipment::SAP_POSTED; @endphp
    <div class="card">
        <h2 class="card-title">Receipts</h2>
        <div class="table-wrap">
            <table class="md-table">
                <thead>
                    <tr>
                        <th>Number</th>
                        <th>Date</th>
                        <th>SAP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shipment->receipts as $receipt)
                        <tr>
                            <td class="mono"><a href="{{ route('admin.stock-transfer-receipts.show', $receipt) }}">{{ $receipt->number }}</a></td>
                            <td class="mono">{{ $receipt->received_at?->format('d/m/Y') }}</td>
                            <td>
                                {{ $receipt->sap_post_status ? ucfirst($receipt->sap_post_status) : '-' }}
                                @if ($receipt->sap_post_status === \App\Models\StockTransferReceipt::SAP_POSTED && $receipt->sap_doc_num)
                                    <span class="mono">{{ \App\Support\Sap\SapStockTransferPosting::OBJ_STOCK_TRANSFER }} {{ $receipt->sap_doc_num }}</span>
                                @elseif (! $shipmentInSap && $receipt->sap_post_status !== \App\Models\StockTransferReceipt::SAP_POSTED)
                                    <div class="hint">Waits for this shipment to be posted to SAP.</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3">No receipts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

<div>
    <div class="form-actions">
        <a href="{{ route('admin.stock-transfer-shipments.index') }}" class="btn btn-outline">Back</a>
    </div>
</div>
@endsection
