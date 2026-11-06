@extends('layouts.admin')

@section('title', 'Stock Adjustments')
@section('page_title', 'Stock Adjustments')

@section('content')
<div class="table-toolbar">
    <h2 style="margin:0; font-size:16px;">Adjustment List</h2>
    <a href="{{ route('admin.stock-adjustments.create') }}" class="btn btn-filled">
        <span class="material-symbols-rounded">add</span> Create Adjustment
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
                    <td style="text-align:right;">
                        @if (! $adj->isPosted())
                            <form method="POST" action="{{ route('admin.stock-adjustments.post', $adj) }}" onsubmit="return confirm('Post this adjustment? Stock will change and it can no longer be edited.')">
                                @csrf
                                <button type="submit" class="btn btn-filled btn-sm">Post</button>
                            </form>
                        @else
                            <span style="color:var(--color-text-muted); font-size:12px;">Final</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">tune</span><p>No stock adjustments yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $adjustments])
@endsection
