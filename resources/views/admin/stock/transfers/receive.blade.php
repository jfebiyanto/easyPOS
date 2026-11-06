@extends('layouts.admin')

@section('title', 'Receive Transfer')
@section('page_title', 'Terima Transfer · '.($transfer->number ?? 'Draft'))

@section('content')
<form method="POST" action="{{ route('admin.stock-transfers.receive', $transfer) }}">
    @csrf

    <div class="card">
        <h2 class="card-title">Goods Receipt</h2>
        <p class="card-subtitle">
            From <strong>{{ $transfer->fromStore?->code }}</strong> to <strong>{{ $transfer->toStore?->code }}</strong>.
            Enter the quantity actually received. If it differs from the quantity shipped, a difference reason is required.
        </p>

        @error('lines')
            <div class="md-snackbar error"><span class="material-symbols-rounded">error</span>{{ $message }}</div>
        @enderror

        <div class="table-wrap">
            <table class="md-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align:right;">Shipped</th>
                        <th style="width:160px;">Qty Received</th>
                        <th>Difference Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transfer->lines as $i => $line)
                        <tr>
                            <td>{{ $line->product?->sku }} · {{ $line->product?->name }}</td>
                            <td style="text-align:right;" class="money">{{ rtrim(rtrim(number_format((float) $line->qty_shipped, 3, ',', '.'), '0'), ',') }}</td>
                            <td>
                                <input type="hidden" name="lines[{{ $i }}][id]" value="{{ $line->id }}">
                                <input type="number" step="0.001" min="0" name="lines[{{ $i }}][qty_received]" value="{{ old("lines.$i.qty_received", $line->qty_shipped) }}" style="width:100%; padding:8px; border:1px solid var(--md-outline); border-radius:var(--md-radius-sm);">
                            </td>
                            <td>
                                <input type="text" name="lines[{{ $i }}][variance_reason]" value="{{ old("lines.$i.variance_reason") }}" maxlength="500" style="width:100%; padding:8px; border:1px solid var(--md-outline); border-radius:var(--md-radius-sm);" placeholder="required if quantity differs">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">check</span> Confirm Receipt</button>
            <a href="{{ route('admin.stock-transfers.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </div>
</form>
@endsection
