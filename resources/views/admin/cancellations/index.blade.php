@extends('layouts.admin')

@section('title', 'Voids & Cancellations')
@section('page_title', 'Voids & Cancellations')

@php
    $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
    $qs = array_filter(request()->only(['from', 'to', 'store', 'cashier', 'type', 'q']));
@endphp

@section('content')
<div class="card" style="margin-bottom:16px;">
    <h2 class="card-title">Voids &amp; Cancellations Report</h2>
    <p class="card-subtitle">Cancellations before payment (F9) and sale voids, with store, cashier, customer, items, time, IP and device. Cancellations made while offline are recorded once reconnected and flagged.</p>

    <form method="GET" action="{{ route('admin.cancellations.index') }}" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        <div class="field" style="margin:0;">
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="{{ $from }}">
        </div>
        <div class="field" style="margin:0;">
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="{{ $to }}">
        </div>
        @if ($stores->isNotEmpty())
            <div class="field" style="margin:0;">
                <label for="store">Store</label>
                <select id="store" name="store">
                    <option value="">All Stores</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected(request('store') == $store->id)>{{ $store->code }} · {{ $store->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="field" style="margin:0;">
            <label for="cashier">Cashier</label>
            <select id="cashier" name="cashier">
                <option value="">All Cashiers</option>
                @foreach ($cashiers as $cashier)
                    <option value="{{ $cashier->id }}" @selected(request('cashier') == $cashier->id)>{{ $cashier->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0;">
            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="">All Types</option>
                @foreach (\App\Models\PosCancellation::TYPE_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0; min-width:220px;">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Customer, sale no., IP, browser…">
        </div>
        <button type="submit" class="btn btn-tonal">Apply</button>
    </form>
</div>

<div class="table-toolbar">
    <div>
        <strong>{{ number_format($summary['count'], 0, ',', '.') }}</strong> pembatalan ·
        nilai <strong>{{ $rupiah($summary['total']) }}</strong>
    </div>
    <div style="display:flex; gap:8px;">
        <button type="button" class="btn btn-tonal btn-sm" data-cancel-toggle="open">Expand all</button>
        <button type="button" class="btn btn-tonal btn-sm" data-cancel-toggle="close">Collapse all</button>
        <a class="btn btn-outline" href="{{ route('admin.cancellations.export', $qs + ['from' => $from, 'to' => $to]) }}">
            <span class="material-symbols-rounded">table_view</span> Excel
        </a>
    </div>
</div>

@forelse ($groups as $group)
    <details class="cancel-group" @if ($loop->first || $groups->count() === 1) open @endif>
        <summary>
            <span class="material-symbols-rounded cancel-chevron" aria-hidden="true">expand_more</span>
            <strong>{{ $group->store_label ?? 'Gerai tidak diketahui' }}</strong>
            <span class="cancel-stat">{{ number_format($group->total_count, 0, ',', '.') }} pembatalan</span>
            <span class="cancel-stat">{{ $rupiah($group->total_value) }}</span>
            @if ((int) $group->void_count > 0)
                <span class="chip chip-danger">{{ (int) $group->void_count }} void</span>
            @endif
            @if ($group->total_count - (int) $group->void_count > 0)
                <span class="chip chip-neutral">{{ $group->total_count - (int) $group->void_count }} batal sebelum bayar</span>
            @endif
        </summary>

        <div class="table-wrap">
            <table class="md-table sap-monitor">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Cashier</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th style="text-align:right;">Total</th>
                        <th>IP · Device</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($group->rows as $c)
                        <tr>
                            <td class="mono" style="white-space:nowrap;">
                                {{ $c->occurred_at->timezone($timezone)->format('d/m/Y H:i:s') }}
                                @if ($c->isLate()) <div><span class="chip chip-neutral" title="Dicatat {{ $c->created_at->timezone($timezone)->format('d/m/Y H:i:s') }}">Offline</span></div> @endif
                            </td>
                            <td>
                                <span class="chip {{ $c->type === 'sale_void' ? 'chip-danger' : 'chip-neutral' }}">{{ \App\Models\PosCancellation::TYPE_LABELS[$c->type] ?? $c->type }}</span>
                                @if ($c->sale_number) <div class="hint mono">{{ $c->sale_number }}</div> @endif
                            </td>
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
                            <td title="{{ $c->user_agent }}">
                                <span class="mono">{{ $c->ip_address ?? '-' }}</span>
                                <div class="hint">{{ $c->browser ?? '-' }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($group->total_count > $group->rows->count())
            <p class="hint cancel-more">Showing the latest {{ $group->rows->count() }} of {{ number_format($group->total_count, 0, ',', '.') }}. Narrow the period, filter by cashier or type, or download the Excel file for the full data.</p>
        @endif
    </details>
@empty
    <div class="table-wrap">
        <div class="empty-state"><span class="material-symbols-rounded">block</span><p>No cancellations in this period.</p></div>
    </div>
@endforelse
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-cancel-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const open = button.dataset.cancelToggle === 'open';
            document.querySelectorAll('details.cancel-group').forEach((group) => { group.open = open; });
        });
    });
</script>
@endpush
