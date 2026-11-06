@extends('layouts.admin')

@section('title', 'New Receipt')
@section('page_title', 'New Receipt')

@php
    $format = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $plain = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
@endphp

@section('content')
@if (! $shipment)
    <div class="card">
        <h2 class="card-title">Select Shipment</h2>
        @if ($receivable->isEmpty())
            <div class="empty-state"><span class="material-symbols-rounded">move_to_inbox</span><p>No shipments awaiting receipt.</p></div>
        @else
            <form method="GET" action="{{ route('admin.stock-transfer-receipts.create') }}">
                <div class="form-grid">
                    <div class="field">
                        <label for="shipment">Shipment *</label>
                        <select id="shipment" name="shipment" required>
                            @foreach ($receivable as $option)
                                <option value="{{ $option->id }}">{{ $option->number }} · {{ $option->fromStore?->code }} → {{ $option->toStore?->code }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-actions" style="margin-top:20px;">
                    <button type="submit" class="btn btn-filled">Continue</button>
                    <a href="{{ route('admin.stock-transfer-receipts.index') }}" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        @endif
    </div>
@else
    <form method="POST" action="{{ route('admin.stock-transfer-receipts.store') }}">
        @csrf
        <input type="hidden" name="shipment_id" value="{{ $shipment->id }}">

        <div class="card">
            <h2 class="card-title">Shipment Information</h2>
            <div class="form-grid">
                <div class="field"><label>Shipment</label><div class="mono">{{ $shipment->number }}</div></div>
                <div class="field"><label>From</label><div>{{ $shipment->fromStore?->code }} · {{ $shipment->fromStore?->name }}</div></div>
                <div class="field"><label>To</label><div>{{ $shipment->toStore?->code }} · {{ $shipment->toStore?->name }}</div></div>
                <div class="field" style="grid-column:1/-1;">
                    <label for="note">Notes</label>
                    <input type="text" id="note" name="note" value="{{ old('note') }}" maxlength="254">
                    @error('note') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">Product Lines</h2>
            @error('lines') <div class="error">{{ $message }}</div> @enderror
            <p style="font-size:12px; color:var(--color-text-muted);">Remove a row to leave that item in transit. Lower the quantity to receive only part of it.</p>
            <div class="table-wrap">
                <table class="md-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align:right;">Shipped</th>
                            <th style="text-align:right;">Already Received</th>
                            <th style="text-align:right;">Remaining</th>
                            <th style="width:140px;">Qty Received</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="receipt-lines">
                        @foreach ($lines as $line)
                            <tr>
                                <td>{{ $line->product?->sku }} · {{ $line->product?->name }}</td>
                                <td style="text-align:right;" class="money">{{ $format($line->qty) }}</td>
                                <td style="text-align:right;" class="money">{{ $format($line->qty_received) }}</td>
                                <td style="text-align:right;" class="money">{{ $format($line->remaining()) }}</td>
                                <td>
                                    <input type="number" step="0.001" min="0.001" max="{{ $plain($line->remaining()) }}" name="lines[{{ $line->id }}][qty]" value="{{ old('lines.'.$line->id.'.qty', $plain($line->remaining())) }}" style="width:100%;" required>
                                </td>
                                <td>
                                    <button type="button" class="icon-btn" data-remove title="Remove row">
                                        <span class="material-symbols-rounded">delete</span>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="form-actions" style="margin-top:20px;">
                <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Receive</button>
                <a href="{{ route('admin.stock-transfer-receipts.index') }}" class="btn btn-outline">Cancel</a>
            </div>
        </div>
    </form>

    @push('scripts')
    <script>
    document.getElementById('receipt-lines').addEventListener('click', function (event) {
        var button = event.target.closest('[data-remove]');
        if (button) { button.closest('tr').remove(); }
    });
    </script>
    @endpush
@endif
@endsection
