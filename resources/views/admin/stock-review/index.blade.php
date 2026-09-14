@extends('layouts.admin')

@section('title', 'Review Stok')
@section('page_title', 'Review Stok')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Transaksi Ber-flag Review</h2>
    <a href="{{ route('admin.eod.index') }}" class="btn btn-tonal">Tutup Toko</a>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>Nomor</th><th>Gerai</th><th>Waktu</th><th>Total</th><th>Alasan</th><th style="text-align:right;">Aksi</th></tr>
        </thead>
        <tbody>
            @forelse ($sales as $sale)
                <tr>
                    <td class="mono">{{ $sale->number ?? 'OFFLINE' }}</td>
                    <td>{{ $sale->store?->code }}</td>
                    <td class="mono">{{ $sale->created_at?->format('d/m/Y H:i') }}</td>
                    <td class="money">Rp {{ number_format((float) $sale->total, 0, ',', '.') }}</td>
                    <td>{{ $sale->review_reason ?? '-' }}</td>
                    <td>
                        <div class="actions-cell">
                            <form method="POST" action="{{ route('admin.stock-review.resolve', $sale) }}">
                                @csrf
                                <input type="hidden" name="action" value="valid">
                                <button type="submit" class="btn btn-filled btn-sm">Tandai Sah</button>
                            </form>
                            <form method="POST" action="{{ route('admin.stock-review.resolve', $sale) }}" onsubmit="return confirm('Void transaksi ini? Stok akan dikembalikan.')">
                                @csrf
                                <input type="hidden" name="action" value="void">
                                <input type="hidden" name="reason" value="Review EOD: stok tidak mencukupi">
                                <button type="submit" class="btn btn-danger btn-sm">Void</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">check_circle</span><p>Tidak ada transaksi yang perlu review.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $sales])
@endsection
