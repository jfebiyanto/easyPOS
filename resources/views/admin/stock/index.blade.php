@extends('layouts.admin')

@section('title', 'Stock Balance')
@section('page_title', 'Stock Balance')

@section('content')
@if ($stores->isNotEmpty())
    <div class="table-toolbar">
        <form method="GET" action="{{ route('admin.stock.index') }}" style="display:flex; gap:8px; align-items:center;">
            <label style="font-size:13px; color:var(--color-text-muted);">Store:</label>
            <select name="store" onchange="this.form.submit()" style="padding:9px 12px; border:1px solid var(--md-outline); border-radius:var(--md-radius-sm); background:var(--md-surface-container-high);">
                @foreach ($stores as $s)
                    <option value="{{ $s->id }}" @selected($storeId === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
                @endforeach
            </select>
        </form>
    </div>
@endif

@if ($store && ! $warehouse)
    <div class="md-snackbar error">
        <span class="material-symbols-rounded">warning</span>
        This store is not linked to a warehouse yet. Set it under Stores so the stock balance appears.
    </div>
@elseif ($warehouse)
    <p class="hint" style="margin-bottom:8px;">Warehouse: <strong>{{ $warehouse->code }} · {{ $warehouse->name }}</strong></p>
@endif

@php $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ','); @endphp

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Product Name</th>
                <th>Unit</th>
                <th style="text-align:right;">Balance</th>
                <th style="text-align:right;">Committed</th>
                <th style="text-align:right;">Ordered</th>
                <th style="text-align:right;">SAP Balance</th>
                <th style="text-align:right;">Difference</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                @php
                    $stock = $balances[$product->id] ?? null;
                    $difference = $stock?->sap_on_hand !== null ? round((float) $stock->on_hand - (float) $stock->sap_on_hand, 3) : null;
                @endphp
                <tr>
                    <td><a href="{{ route('admin.stock.mutations', ['product' => $product, 'store' => $storeId]) }}"><strong class="sku">{{ $product->sku }}</strong></a></td>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->unit?->code ?? '-' }}</td>
                    <td style="text-align:right;" class="money {{ (float) $stock?->on_hand < 0 ? 'neg' : '' }}">{{ $fmt($stock?->on_hand ?? 0) }}</td>
                    <td style="text-align:right;" class="money">{{ $fmt($stock?->committed ?? 0) }}</td>
                    <td style="text-align:right;" class="money">{{ $fmt($stock?->on_order ?? 0) }}</td>
                    <td style="text-align:right;" class="money">{{ $stock?->sap_on_hand !== null ? $fmt($stock->sap_on_hand) : '-' }}</td>
                    <td style="text-align:right;" class="money {{ $difference ? 'neg' : '' }}">
                        @if ($difference)
                            <span title="easyPOS balance differed from the SAP B1 balance at the last check">{{ $difference > 0 ? '+' : '' }}{{ $fmt($difference) }}</span>
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-rounded">warehouse</span><p>No stock-tracked products yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($transitWarehouse)
    @php $inTransit = $products->filter(fn ($product) => round((float) ($transitBalances[$product->id]->on_hand ?? 0), 3) != 0); @endphp
    <h2 style="margin:24px 0 8px; font-size:16px;">In-Transit Warehouse {{ $transitWarehouse->code }}</h2>
    <p class="hint" style="margin-bottom:8px;">Stock shipped from this store that has not been received yet.</p>
    <div class="table-wrap">
        <table class="md-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th>Unit</th>
                    <th style="text-align:right;">Balance</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($inTransit as $product)
                    @php $transitOnHand = (float) $transitBalances[$product->id]->on_hand; @endphp
                    <tr>
                        <td><a href="{{ route('admin.stock.mutations', ['product' => $product, 'store' => $storeId, 'transit' => 1]) }}"><strong class="sku">{{ $product->sku }}</strong></a></td>
                        <td>{{ $product->name }}</td>
                        <td>{{ $product->unit?->code ?? '-' }}</td>
                        <td style="text-align:right;" class="money {{ $transitOnHand < 0 ? 'neg' : '' }}">{{ $fmt($transitOnHand) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="empty-state"><span class="material-symbols-rounded">local_shipping</span><p>No stock in transit.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endif
@endsection
