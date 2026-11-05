@extends('layouts.admin')

@section('title', 'Integration Monitor')
@section('page_title', 'Integration Monitor')

@php
    $canManage = auth()->user()->isOwner();
    $statusLabels = ['pending' => 'Menunggu', 'invoiced' => 'Invoice terposting', 'posted' => 'Terposting', 'failed' => 'Gagal'];
    $statusChips = ['pending' => 'chip-neutral', 'invoiced' => 'chip-info', 'posted' => 'chip-success', 'failed' => 'chip-danger'];
    $money = fn ($n) => number_format((float) $n, 2, ',', '.');
@endphp

@section('content')
<div class="tabs">
    <a href="{{ route('admin.sap-postings.index') }}" class="{{ $status ? '' : 'active' }}">Semua</a>
    @foreach ($statusLabels as $key => $label)
        <a href="{{ route('admin.sap-postings.index', ['status' => $key]) }}" class="{{ $status === $key ? 'active' : '' }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
    @endforeach
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>No. Penjualan</th>
                <th>Tanggal</th>
                <th>Gerai</th>
                <th>Customer</th>
                <th style="text-align:right;">Total</th>
                <th>Status</th>
                <th>A/R Invoice SAP</th>
                <th>Incoming Payment SAP</th>
                <th style="text-align:right;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sales as $sale)
                <tr>
                    <td class="mono">{{ $sale->number }}</td>
                    <td class="mono">{{ $sale->business_date?->format('d/m/Y') }}</td>
                    <td>{{ $sale->store?->code ?? '-' }}</td>
                    <td>{{ $sale->customer ? ($sale->customer->code ?? '-').' · '.$sale->customer->name : '-' }}</td>
                    <td style="text-align:right;" class="money">{{ $money($sale->total) }}</td>
                    <td>
                        <span class="chip {{ $statusChips[$sale->sap_post_status] ?? 'chip-neutral' }}">{{ $statusLabels[$sale->sap_post_status] ?? $sale->sap_post_status }}</span>
                        @if ($sale->sap_post_error)
                            <div class="hint" style="color: var(--color-danger);">{{ $sale->sap_post_error }}</div>
                        @endif
                    </td>
                    <td class="mono">{{ $sale->sap_invoice_doc_num ?: '-' }}</td>
                    <td class="mono">{{ $sale->sap_payment_doc_num ?: '-' }}</td>
                    <td style="text-align:right;">
                        @if ($canManage && $sale->sap_post_status === 'failed')
                            <form method="POST" action="{{ route('admin.sap-postings.retry', $sale) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline"><span class="material-symbols-rounded">sync</span> Kirim Ulang</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="empty-state"><span class="material-symbols-rounded">receipt_long</span><p>Belum ada penjualan yang diposting ke SAP B1.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $sales->links() }}
@endsection
