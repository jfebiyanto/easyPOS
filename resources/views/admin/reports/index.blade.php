@extends('layouts.admin')

@section('title', 'Reports')
@section('page_title', 'Sales Reports')

@section('content')
<div class="card">
    <h2 class="card-title">Period Filter</h2>
    <form method="GET" action="{{ route('admin.reports.index') }}">
        <div class="form-grid">
            <div class="field">
                <label for="from">From Date</label>
                <input type="date" id="from" name="from" value="{{ $from }}">
            </div>
            <div class="field">
                <label for="to">To Date</label>
                <input type="date" id="to" name="to" value="{{ $to }}">
            </div>
            @if ($stores->isNotEmpty())
                <div class="field">
                    <label for="store">Store (optional)</label>
                    <select id="store" name="store">
                        <option value="">All Stores</option>
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}" @selected(request('store') == $store->id)>{{ $store->code }} · {{ $store->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </form>
</div>

<div class="stat-grid">
    @foreach ($reports as $key => $label)
        <a class="stat-card" href="{{ route('admin.reports.show', ['report' => $key, 'from' => $from, 'to' => $to, 'store' => request('store')]) }}" style="text-decoration:none; color:inherit;">
            <div class="stat-icon"><span class="material-symbols-rounded">bar_chart</span></div>
            <div>
                <div class="stat-value" style="font-size:15px;">{{ $label }}</div>
                <div class="stat-label">View &amp; export</div>
            </div>
        </a>
    @endforeach
</div>
@endsection
