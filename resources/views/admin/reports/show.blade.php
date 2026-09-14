@extends('layouts.admin')

@section('title', $data['title'])
@section('page_title', $data['title'])

@section('content')
@php
    $isMoney = function (string $col): bool {
        foreach (['Total', 'Omzet', 'Subtotal', 'PPN', 'Retur', 'Net', 'Rata-rata', 'Belanja'] as $needle) {
            if (str_contains($col, $needle)) return true;
        }
        return false;
    };
    $fmt = function ($value, bool $money) {
        if (! is_numeric($value)) return $value;
        return $money
            ? 'Rp '.number_format((float) $value, 0, ',', '.')
            : rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    };
    $qs = array_filter(['from' => $data['from'], 'to' => $data['to'], 'store' => request('store')]);
@endphp

<div class="tabs">
    @foreach ($reports as $key => $label)
        <a href="{{ route('admin.reports.show', array_merge(['report' => $key], $qs)) }}" class="{{ $key === $data['report'] ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="table-toolbar">
    <form method="GET" action="{{ route('admin.reports.show', ['report' => $data['report']]) }}" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        <div class="field" style="margin:0;">
            <label for="from">Dari</label>
            <input type="date" id="from" name="from" value="{{ $data['from'] }}">
        </div>
        <div class="field" style="margin:0;">
            <label for="to">Sampai</label>
            <input type="date" id="to" name="to" value="{{ $data['to'] }}">
        </div>
        @if ($stores->isNotEmpty())
            <div class="field" style="margin:0;">
                <label for="store">Gerai</label>
                <select id="store" name="store">
                    <option value="">Semua Gerai</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected($selectedStore == $store->id)>{{ $store->code }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <button type="submit" class="btn btn-tonal">Terapkan</button>
    </form>

    <div style="display:flex; gap:8px;">
        <a class="btn btn-outline" href="{{ route('admin.reports.export', array_merge(['report' => $data['report'], 'format' => 'xlsx'], $qs)) }}">
            <span class="material-symbols-rounded">table_view</span> Excel
        </a>
        <a class="btn btn-outline" href="{{ route('admin.reports.export', array_merge(['report' => $data['report'], 'format' => 'pdf'], $qs)) }}">
            <span class="material-symbols-rounded">picture_as_pdf</span> PDF
        </a>
    </div>
</div>

<div class="table-wrap">
    <table class="md-table">
        <thead>
            <tr>
                @foreach ($data['columns'] as $i => $col)
                    <th class="{{ in_array($i, $data['numeric'] ?? [], true) ? 'num' : '' }}" style="{{ in_array($i, $data['numeric'] ?? [], true) ? 'text-align:right;' : '' }}">{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($data['rows'] as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        <td style="{{ in_array($i, $data['numeric'] ?? [], true) ? 'text-align:right;' : '' }}" class="{{ in_array($i, $data['numeric'] ?? [], true) ? 'money' : '' }}">
                            {{ $fmt($cell, $isMoney($data['columns'][$i] ?? '')) }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($data['columns']) }}"><div class="empty-state">Tidak ada data pada periode ini.</div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
