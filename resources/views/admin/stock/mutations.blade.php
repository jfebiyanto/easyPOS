@extends('layouts.admin')

@section('title', 'Mutasi Stok')
@section('page_title', 'Mutasi Stok · '.$product->sku)

@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
    $typeLabels = [
        'opening' => 'Saldo Awal', 'sale' => 'Penjualan', 'sale_return' => 'Retur Penjualan',
        'purchase' => 'Pembelian', 'purchase_return' => 'Retur Pembelian',
        'goods_receipt' => 'Goods Receipt', 'goods_issue' => 'Goods Issue',
        'transfer_in' => 'Transfer Masuk', 'transfer_out' => 'Transfer Keluar',
        'adjustment' => 'Penyesuaian', 'production' => 'Produksi', 'sap_other' => 'Lainnya (SAP)',
    ];
@endphp

@section('content')
<div class="card">
    <h2 class="card-title">{{ $product->name }}</h2>
    <p class="card-subtitle">
        Warehouse: <strong>{{ $warehouse ? $warehouse->code.' · '.$warehouse->name : '-' }}</strong>
        · Saldo: <strong class="money">{{ $fmt($stock?->on_hand ?? 0) }}</strong>
        @if ($stock?->sap_on_hand !== null)
            · Saldo SAP: <strong class="money">{{ $fmt($stock->sap_on_hand) }}</strong>
            ({{ $stock->sap_checked_at?->timezone(company_timezone())->format('d/m/Y H:i') }})
        @endif
    </p>

    <div class="table-wrap">
        <table class="md-table">
            <thead>
                <tr>
                    <th>Tgl Dokumen</th>
                    <th>Jenis</th>
                    <th>Dokumen SAP</th>
                    <th style="text-align:right;">Qty</th>
                    <th style="text-align:right;">Saldo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries ?? [] as $entry)
                    <tr>
                        <td class="mono">{{ ($entry->doc_date ?? $entry->created_at)?->format('d/m/Y') }}</td>
                        <td><span class="chip chip-neutral">{{ $typeLabels[$entry->type] ?? $entry->type }}</span></td>
                        <td class="mono">{{ $entry->sap_doc_num ? $entry->sap_doc_num.' / baris '.($entry->sap_doc_line + 1) : '-' }}</td>
                        <td style="text-align:right;" class="money {{ (float) $entry->qty < 0 ? 'neg' : 'pos' }}">
                            {{ (float) $entry->qty > 0 ? '+' : '' }}{{ $fmt($entry->qty) }}
                        </td>
                        <td style="text-align:right;" class="money">{{ $fmt($entry->balance_after) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-rounded">history</span><p>Belum ada mutasi stok.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($entries)
        {{ $entries->links() }}
    @endif

    <div class="form-actions" style="margin-top:16px;">
        <a href="{{ route('admin.stock.index', ['store' => $storeId]) }}" class="btn btn-outline">Kembali</a>
    </div>
</div>
@endsection
