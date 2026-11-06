@extends('layouts.admin')

@section('title', 'Cashier Sessions')
@section('page_title', 'Cashier Sessions')

@php
    $tabs = ['' => 'All', 'active' => 'Active', 'ended' => 'Ended / inactive'];
    $statusChip = fn ($s) => $s->ended_at === null ? ($s->isStale() ? 'chip-neutral' : 'chip-success') : ($s->ended_reason === 'taken_over' ? 'chip-danger' : 'chip-neutral');
@endphp

@section('content')
<div class="tabs">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('admin.pos-sessions.index', array_filter(['status' => $key, 'q' => request('q')])) }}" class="{{ ($status ?? '') === $key ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="table-toolbar">
    <form method="GET" action="{{ route('admin.pos-sessions.index') }}" class="search-form" style="display:flex; gap:8px; flex:0 1 57%; min-width:320px;">
        @if ($status) <input type="hidden" name="status" value="{{ $status }}"> @endif
        <input class="search-box" style="flex:1; min-width:0; max-width:none;" type="search" name="q" value="{{ request('q') }}" placeholder="Search cashier, browser or IP…">
        <button type="submit" class="btn btn-tonal">Search</button>
    </form>
</div>

<div class="table-wrap">
    <table class="md-table sap-monitor">
        <thead>
            <tr>
                <th>Cashier</th>
                <th>Store</th>
                <th>Browser</th>
                <th>IP</th>
                <th>Started</th>
                <th>Last active</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sessions as $session)
                <tr>
                    <td>{{ $session->user?->name ?? '-' }} <span class="hint">{{ $session->user?->username }}</span></td>
                    <td>{{ $session->store?->code ?? '-' }}</td>
                    <td title="{{ $session->user_agent }}">{{ $session->browser ?? '-' }}</td>
                    <td class="mono">{{ $session->ip_address ?? '-' }}</td>
                    <td class="mono" style="white-space:nowrap;">{{ $session->started_at->timezone(\App\Models\CompanyDetail::current()->timezone ?: 'Asia/Jakarta')->format('d/m/Y H:i:s') }}</td>
                    <td class="mono" style="white-space:nowrap;">{{ $session->last_seen_at->timezone(\App\Models\CompanyDetail::current()->timezone ?: 'Asia/Jakarta')->format('d/m/Y H:i:s') }}</td>
                    <td><span class="chip {{ $statusChip($session) }}">{{ $session->statusLabel() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><span class="material-symbols-rounded">devices</span><p>No POS sessions yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $sessions->links() }}
@endsection
