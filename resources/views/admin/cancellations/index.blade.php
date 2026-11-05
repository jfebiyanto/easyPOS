@extends('layouts.admin')

@section('title', 'Pembatalan Transaksi')
@section('page_title', 'Pembatalan Transaksi')

@php
    $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
    $qs = array_filter(request()->only(['from', 'to', 'store', 'cashier', 'type', 'q']));
@endphp

@section('content')
<div class="card" style="margin-bottom:16px;">
    <h2 class="card-title">Laporan Pembatalan Transaksi</h2>
    <p class="card-subtitle">Batal sebelum bayar (F9) dan void penjualan, lengkap dengan gerai, kasir, pelanggan, isi barang, waktu, IP, dan perangkat. Pembatalan yang terjadi saat offline dicatat setelah tersambung dan diberi tanda.</p>

    <form method="GET" action="{{ route('admin.cancellations.index') }}" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        <div class="field" style="margin:0;">
            <label for="from">Dari</label>
            <input type="date" id="from" name="from" value="{{ $from }}">
        </div>
        <div class="field" style="margin:0;">
            <label for="to">Sampai</label>
            <input type="date" id="to" name="to" value="{{ $to }}">
        </div>
        @if ($stores->isNotEmpty())
            <div class="field" style="margin:0;">
                <label for="store">Gerai</label>
                <select id="store" name="store">
                    <option value="">Semua Gerai</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected(request('store') == $store->id)>{{ $store->code }} · {{ $store->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="field" style="margin:0;">
            <label for="cashier">Kasir</label>
            <select id="cashier" name="cashier">
                <option value="">Semua Kasir</option>
                @foreach ($cashiers as $cashier)
                    <option value="{{ $cashier->id }}" @selected(request('cashier') == $cashier->id)>{{ $cashier->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0;">
            <label for="type">Jenis</label>
            <select id="type" name="type">
                <option value="">Semua Jenis</option>
                @foreach (\App\Models\PosCancellation::TYPE_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0; min-width:220px;">
            <label for="q">Cari</label>
            <input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Pelanggan, no. penjualan, IP, browser…">
        </div>
        <button type="submit" class="btn btn-tonal">Terapkan</button>
    </form>
</div>

<div class="table-toolbar">
    <div>
        <strong>{{ number_format($summary['count'], 0, ',', '.') }}</strong> pembatalan ·
        nilai <strong>{{ $rupiah($summary['total']) }}</strong>
    </div>
    <a class="btn btn-outline" href="{{ route('admin.cancellations.export', $qs + ['from' => $from, 'to' => $to]) }}">
        <span class="material-symbols-rounded">table_view</span> Excel
    </a>
</div>

<div class="table-wrap">
    <table class="md-table sap-monitor">
        <thead>
            <tr>
                <th>Waktu</th>
                <th>Jenis</th>
                <th>Gerai</th>
                <th>Kasir</th>
                <th>Pelanggan</th>
                <th>Barang</th>
                <th style="text-align:right;">Total</th>
                <th>IP</th>
                <th>Perangkat</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cancellations as $c)
                <tr>
                    <td class="mono" style="white-space:nowrap;">
                        {{ $c->occurred_at->timezone($timezone)->format('d/m/Y H:i:s') }}
                        @if ($c->isLate()) <div><span class="chip chip-neutral" title="Dicatat {{ $c->created_at->timezone($timezone)->format('d/m/Y H:i:s') }}">Offline</span></div> @endif
                    </td>
                    <td>
                        <span class="chip {{ $c->type === 'sale_void' ? 'chip-danger' : 'chip-neutral' }}">{{ \App\Models\PosCancellation::TYPE_LABELS[$c->type] ?? $c->type }}</span>
                        @if ($c->sale_number) <div class="hint mono">{{ $c->sale_number }}</div> @endif
                    </td>
                    <td>{{ $c->store_label ?? '-' }}</td>
                    <td>{{ $c->cashier_name ?? '-' }}</td>
                    <td>{{ $c->customer_label ?? '-' }}</td>
                    <td style="min-width:220px;">
                        @if (! empty($c->items))
                            <details>
                                <summary>{{ $c->item_count }} barang</summary>
                                <ul style="margin:6px 0 0; padding-left:18px;">
                                    @foreach ($c->items as $item)
                                        <li>{{ $qty($item['qty']) }} × {{ $item['name'] ?? '-' }} @if (! empty($item['sku'])) <span class="hint mono">{{ $item['sku'] }}</span> @endif — {{ $rupiah($item['line_total']) }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @else
                            {{ $c->item_count }} barang
                        @endif
                        @if ($c->reason) <div class="hint">Alasan: {{ $c->reason }}</div> @endif
                    </td>
                    <td style="text-align:right;" class="money">{{ $rupiah($c->total) }}</td>
                    <td class="mono">{{ $c->ip_address ?? '-' }}</td>
                    <td title="{{ $c->user_agent }}">{{ $c->browser ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="empty-state"><span class="material-symbols-rounded">block</span><p>Tidak ada pembatalan pada periode ini.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $cancellations->links() }}
@endsection
