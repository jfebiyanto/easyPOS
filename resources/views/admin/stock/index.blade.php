@extends('layouts.admin')

@section('title', 'Saldo Stok')
@section('page_title', 'Saldo Stok')

@section('content')
<div class="tabs">
    <a href="{{ route('admin.stock.index') }}" class="active">Saldo Stok</a>
    <a href="{{ route('admin.stock-adjustments.index') }}">Penyesuaian Stok</a>
    <a href="{{ route('admin.stock-transfers.index') }}">Transfer Stok</a>
</div>

@if ($stores->isNotEmpty())
    <div class="table-toolbar">
        <form method="GET" action="{{ route('admin.stock.index') }}" style="display:flex; gap:8px; align-items:center;">
            <label style="font-size:13px; color:var(--color-text-muted);">Gerai:</label>
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
        Gerai ini belum dihubungkan ke warehouse. Atur di Daftar Gerai agar saldo stok tampil.
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
                <th>Nama Produk</th>
                <th>Satuan</th>
                <th style="text-align:right;">Saldo</th>
                <th style="text-align:right;">Komitmen</th>
                <th style="text-align:right;">Dipesan</th>
                <th style="text-align:right;">Saldo SAP</th>
                <th style="text-align:right;">Selisih</th>
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
                            <span title="Saldo easyPOS berbeda dengan saldo SAP B1 saat pengecekan terakhir">{{ $difference > 0 ? '+' : '' }}{{ $fmt($difference) }}</span>
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-rounded">warehouse</span><p>Belum ada produk yang dilacak stoknya.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
