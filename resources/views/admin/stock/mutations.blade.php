@extends('layouts.admin')

@section('title', 'Stock Mutations')
@section('page_title', 'Mutasi Stok · '.$product->sku)

@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
    $typeLabels = [
        'opening' => 'Opening Balance', 'sale' => 'Sale', 'sale_return' => 'Sales Return',
        'purchase' => 'Purchase', 'purchase_return' => 'Purchase Return',
        'goods_receipt' => 'Goods Receipt', 'goods_issue' => 'Goods Issue',
        'transfer_in' => 'Transfer In', 'transfer_out' => 'Transfer Out',
        'adjustment' => 'Adjustment', 'production' => 'Production', 'sap_other' => 'Other (SAP)',
    ];
@endphp

@section('content')
<div class="card">
    <h2 class="card-title">{{ $product->name }}</h2>
    <p class="card-subtitle">
        Warehouse: <strong>{{ $warehouse ? $warehouse->code.' · '.$warehouse->name : '-' }}</strong>
        · Balance: <strong class="money">{{ $fmt($stock?->on_hand ?? 0) }}</strong>
        @if ($stock?->sap_on_hand !== null)
            · Saldo SAP: <strong class="money">{{ $fmt($stock->sap_on_hand) }}</strong>
            ({{ $stock->sap_checked_at?->timezone(company_timezone())->format('d/m/Y H:i') }})
        @endif
    </p>

    <div class="table-wrap">
        <table class="md-table">
            <thead>
                <tr>
                    <th>Document Date</th>
                    <th>Type</th>
                    <th>SAP Document</th>
                    <th style="text-align:right;">Qty</th>
                    <th style="text-align:right;">Balance</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries ?? [] as $entry)
                    <tr>
                        <td class="mono">{{ ($entry->doc_date ?? $entry->created_at)?->format('d/m/Y') }}</td>
                        <td><span class="chip chip-neutral">{{ $typeLabels[$entry->type] ?? $entry->type }}</span></td>
                        <td>{{ $entry->sapDocumentLabel() ?? '-' }}</td>
                        <td style="text-align:right;" class="money {{ (float) $entry->qty < 0 ? 'neg' : 'pos' }}">
                            {{ (float) $entry->qty > 0 ? '+' : '' }}{{ $fmt($entry->qty) }}
                        </td>
                        <td style="text-align:right;" class="money">{{ $fmt($entry->balance_after) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-rounded">history</span><p>No stock mutations yet.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($entries)
        @include('partials.pagination', ['paginator' => $entries])
    @endif

    <div class="form-actions" style="margin-top:16px;">
        <a href="{{ route('admin.stock.index', ['store' => $storeId]) }}" class="btn btn-outline">Back</a>
    </div>
</div>
@endsection
