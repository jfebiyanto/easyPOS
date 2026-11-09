@extends('layouts.admin')

@section('title', 'Stock Counting')
@section('page_title', 'Stock Counting')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Stock Counting List</h2>
    <a href="{{ route('admin.stock-adjustments.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Create Stock Counting
    </a>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Number</th>
                <th>Store</th>
                <th>Reason</th>
                <th>Items</th>
                <th>Status</th>
                <th>SAP B1</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($adjustments as $adj)
                <tr>
                    <td class="mono">{{ $adj->date?->format('d/m/Y') }}</td>
                    <td class="mono">{{ $adj->number ?? '-' }}</td>
                    <td>{{ $adj->store?->code ?? '-' }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($adj->reason, 40) ?: '-' }}</td>
                    <td>{{ $adj->lines->count() }}</td>
                    <td>
                        @if ($adj->isPosted())
                            <span class="chip chip-success"><span class="material-symbols-rounded">check</span>Posted</span>
                        @else
                            <span class="chip chip-pending"><span class="material-symbols-rounded">schedule</span>Draft</span>
                        @endif
                    </td>
                    <td style="min-width:160px; max-width:260px;">
                        @if ($adj->sap_post_status)
                            @php $sapChip = ['pending' => 'chip-neutral', 'posted' => 'chip-success', 'failed' => 'chip-danger'][$adj->sap_post_status] ?? 'chip-neutral'; @endphp
                            <span class="chip {{ $sapChip }}">{{ ucfirst($adj->sap_post_status) }}</span>
                            @if ($adj->sap_receipt_doc_num)
                                <div class="hint mono">Goods Receipt {{ $adj->sap_receipt_doc_num }}</div>
                            @endif
                            @if ($adj->sap_issue_doc_num)
                                <div class="hint mono">Goods Issue {{ $adj->sap_issue_doc_num }}</div>
                            @endif
                            @if ($adj->sap_post_error)
                                <div class="hint" style="color: var(--color-danger);">{{ $adj->sap_post_error }}</div>
                            @endif
                        @else
                            <span style="color:var(--color-text-muted);">-</span>
                        @endif
                    </td>
                    <td style="text-align:right;">
                        @if ($adj->isPosted() && $adj->sap_post_status === 'failed' && auth()->user()->isOwner())
                            <form method="POST" action="{{ route('admin.stock-adjustments.retry', $adj) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm"><span class="material-symbols-rounded">sync</span> Resend</button>
                            </form>
                        @elseif (! $adj->isPosted())
                            <form method="POST" action="{{ route('admin.stock-adjustments.post', $adj) }}" onsubmit="return confirm('Post this stock counting? Stock will change and it can no longer be edited.')">
                                @csrf
                                <button type="submit" class="btn btn-filled btn-sm">Post</button>
                            </form>
                        @else
                            <span style="color:var(--color-text-muted); font-size:12px;">Final</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-rounded">tune</span><p>No stock counting yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $adjustments])
@endsection
