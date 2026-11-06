@extends('layouts.admin')

@section('title', 'Deliveries')
@section('page_title', 'Deliveries')

@section('content')
<div class="table-toolbar">
    <form method="GET" action="{{ route('admin.deliveries.index') }}" style="display:flex; gap:8px; align-items:center;">
        <label style="font-size:13px; color:var(--color-text-muted);">Status:</label>
        <select name="status" onchange="this.form.submit()" style="padding:9px 12px; border:1px solid var(--md-outline); border-radius:var(--md-radius-sm); background:var(--md-surface-container-high);">
            <option value="">All</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($selectedStatus === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr><th>Transaction No.</th><th>Recipient</th><th>Address</th><th>Store</th><th>Status</th><th style="text-align:right;">Update Status</th></tr>
        </thead>
        <tbody>
            @forelse ($deliveries as $delivery)
                <tr>
                    <td class="mono">{{ $delivery->sale?->number ?? '-' }}</td>
                    <td>{{ $delivery->recipient_name }}<br><span style="font-size:12px; color:var(--color-text-muted);">{{ $delivery->phone }}</span></td>
                    <td>{{ \Illuminate\Support\Str::limit($delivery->address, 50) }}</td>
                    <td>{{ $delivery->sale?->store?->code ?? '-' }}</td>
                    <td>
                        @php
                            $chips = ['pending' => 'chip-pending', 'shipped' => 'chip-info', 'delivered' => 'chip-success', 'cancelled' => 'chip-danger'];
                        @endphp
                        <span class="chip {{ $chips[$delivery->status] ?? 'chip-neutral' }}">{{ ucfirst($delivery->status) }}</span>
                    </td>
                    <td style="text-align:right;">
                        <form method="POST" action="{{ route('admin.deliveries.update', $delivery) }}" style="display:flex; gap:6px; justify-content:flex-end;">
                            @csrf @method('PUT')
                            <select name="status" style="padding:6px 8px; border:1px solid var(--md-outline); border-radius:var(--radius-sm);">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" @selected($delivery->status === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-tonal btn-sm">Save</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-rounded">local_shipping</span><p>No deliveries yet.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('partials.pagination', ['paginator' => $deliveries])
@endsection
