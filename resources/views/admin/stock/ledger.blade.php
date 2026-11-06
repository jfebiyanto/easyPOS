@extends('layouts.admin')

@section('title', 'Stock History')
@section('page_title', 'Riwayat Stok · '.$product->sku)

@section('content')
<div class="card">
    <h2 class="card-title">{{ $product->name }}</h2>
    <p class="card-subtitle">
        Store: <strong>{{ $store?->code ?? '-' }}</strong> · Current balance:
        <strong class="money">{{ rtrim(rtrim(number_format($balance, 3, ',', '.'), '0'), ',') }}</strong>
    </p>

    <div class="table-wrap">
        <table class="md-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Type</th>
                    <th style="text-align:right;">Qty</th>
                    <th>Reference</th>
                    <th>By</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td class="mono">{{ $entry->created_at?->format('d/m/Y H:i') }}</td>
                        <td><span class="chip chip-neutral">{{ $entry->type }}</span></td>
                        <td style="text-align:right;" class="money {{ (float) $entry->qty < 0 ? 'neg' : 'pos' }}">
                            {{ (float) $entry->qty > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $entry->qty, 3, ',', '.'), '0'), ',') }}
                        </td>
                        <td class="mono">{{ $entry->ref_type ? $entry->ref_type.'#'.substr((string) $entry->ref_id, 0, 8) : '-' }}</td>
                        <td>{{ $entry->actor?->name ?? '-' }}</td>
                        <td>{{ $entry->note ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">history</span><p>No stock movements yet.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="form-actions" style="margin-top:16px;">
        <a href="{{ route('admin.stock.index', ['store' => $storeId]) }}" class="btn btn-outline">Back</a>
    </div>
</div>
@endsection
