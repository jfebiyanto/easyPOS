@extends('layouts.admin')

@section('title', 'Integration Monitor')
@section('page_title', 'Integration Monitor')

@php
    $canManage = auth()->user()->isOwner();
    $statusLabels = ['pending' => 'Pending', 'credited' => 'Credit Memo posted', 'posted' => 'Posted', 'failed' => 'Failed'];
    $statusChips = ['pending' => 'chip-neutral', 'credited' => 'chip-info', 'posted' => 'chip-success', 'failed' => 'chip-danger'];
    $money = fn ($n) => number_format((float) $n, 2, ',', '.');
@endphp

@section('content')
<div class="tabs">
    <a href="{{ route('admin.sap-postings.index') }}">Sales</a>
    <a href="{{ route('admin.sap-postings.returns') }}" class="active">Returns</a>
    <a href="{{ route('admin.sap-postings.inventory') }}">Inventory</a>
</div>
<div class="tabs">
    <a href="{{ route('admin.sap-postings.returns') }}" class="{{ $status ? '' : 'active' }}">All</a>
    @foreach ($statusLabels as $key => $label)
        <a href="{{ route('admin.sap-postings.returns', ['status' => $key]) }}" class="{{ $status === $key ? 'active' : '' }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
    @endforeach
</div>

<div class="table-wrap">
    <table class="md-table sap-monitor">
        <thead>
            <tr>
                <th>Return No.</th>
                <th>Date</th>
                <th>Store</th>
                <th>Sale No.</th>
                <th style="text-align:right;">Total</th>
                <th>Status</th>
                <th>A/R Credit Memo SAP</th>
                <th>Outgoing Payment SAP</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($returns as $return)
                <tr>
                    <td class="mono" style="white-space:nowrap;">{{ $return->number }}</td>
                    <td class="mono">{{ $return->created_at?->format('d/m/Y') }}</td>
                    <td>{{ $return->store?->code ?? '-' }}</td>
                    <td class="mono" style="white-space:nowrap;">{{ $return->originalSale?->number ?? '-' }}</td>
                    <td style="text-align:right;" class="money">{{ $money($return->total) }}</td>
                    <td style="min-width:180px; max-width:260px;">
                        <span class="chip {{ $statusChips[$return->sap_post_status] ?? 'chip-neutral' }}">{{ $statusLabels[$return->sap_post_status] ?? $return->sap_post_status }}</span>
                        @if ($return->sap_post_error)
                            <div class="hint" style="color: var(--color-danger);">{{ $return->sap_post_error }}</div>
                        @endif
                    </td>
                    <td class="mono">{{ $return->sap_credit_memo_doc_num ?: '-' }}</td>
                    <td class="mono">{{ $return->sap_refund_doc_num ?: '-' }}</td>
                    <td style="text-align:right; white-space:nowrap;">
                        @if ($canManage && $return->sap_post_status === 'failed')
                            <form method="POST" action="{{ route('admin.sap-postings.returns.retry', $return) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm"><span class="material-symbols-rounded">sync</span> Resend</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="empty-state"><span class="material-symbols-rounded">assignment_return</span><p>No returns have been posted to SAP B1 yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $returns->links() }}
@endsection
